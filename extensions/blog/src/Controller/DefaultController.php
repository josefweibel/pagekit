<?php

namespace Pagekit\Blog\Controller;

use Pagekit\Blog\BlogExtension;
use Pagekit\Blog\Entity\Comment;
use Pagekit\Blog\Entity\Post;
use Pagekit\Comment\Event\CommentEvent;
use Pagekit\Comment\Model\CommentInterface;
use Pagekit\Component\Database\ORM\Repository;
use Pagekit\Framework\Controller\Controller;
use Pagekit\Framework\Controller\Exception;

/**
 * @Route("/blog")
 */
class DefaultController extends Controller
{
    /**
     * @var BlogExtension
     */
    protected $extension;

    /**
     * @var Repository
     */
    protected $posts;

    /**
     * @var Repository
     */
    protected $comments;

    /**
     * Constructor.
     *
     * @param BlogExtension $extension
     */
    public function __construct(BlogExtension $extension)
    {
        $this->extension = $extension;
        $this->posts     = $this['db.em']->getRepository('Pagekit\Blog\Entity\Post');
        $this->comments  = $this['db.em']->getRepository('Pagekit\Blog\Entity\Comment');
    }

    /**
     * @Response("blog/post/index.razr")
     */
    public function indexAction()
    {
        $posts = $this->posts->query()->where(['status' => Post::STATUS_PUBLISHED])->where('date < :date', [':date' => (new \DateTime)->format('Y-m-d H:i:s')])->related('user')->orderBy('date', 'DESC')->get();

        foreach ($posts as $post) {
            $post->setContent($this['content']->applyPlugins($post->getContent(), ['post' => $post, 'markdown' => $post->get('markdown'), 'readmore' => true]));
        }

        return ['head.title' => __('Blog'), 'posts' => $posts, 'config' => $this->extension->getConfig()];
    }

    /**
     * @Route("/comment")
     * @Request({"post_id": "int", "comment": "array"}, csrf=true)
     */
    public function commentAction($id, $data)
    {
        try {

            $user = $this['user'];

            if (!$user->hasAccess('blog: post comments')) {
                throw new Exception(__('Insufficient User Rights.'));
            }

            // check minimum idle time in between user comments
            if (!$user->hasAccess('blog: skip comment min idle')
                and $minidle = $this->extension->getConfig('comments.minidle')
                and $comment = $this->comments->query()->where($user->isAuthenticated() ? ['user_id' => $user->getId()] : ['ip' => $this['request']->getClientIp()])->orderBy('created', 'DESC')->first()) {

                $diff = $comment->getCreated()->diff(new \DateTime("- {$minidle} sec"));

                if ($diff->invert) {
                    throw new Exception(__('Please wait another %seconds% seconds before commenting again.', ['%seconds%' => $diff->s+$diff->i*60+$diff->h*3600]));
                }
            }

            if (!$post = $this->posts->query()->where(['id' => $id, 'status' => Post::STATUS_PUBLISHED])->first()) {
                throw new Exception(__('Insufficient User Rights.'));
            }

            if (!$post->getCommentStatus()) {
                throw new Exception(__('Comments have been disabled for this post.'));
            }

            // retrieve user data
            if ($user->isAuthenticated()) {
                $data['author'] = $user->getName();
                $data['email'] = $user->getEmail();
                $data['url'] = $user->getUrl();
            } elseif ($this->extension->getConfig('comments.require_name_and_email') && (!$data['author'] || !$data['email'])) {
                throw new Exception(__('Please provide valid name and email.'));
            }

            $comment = new Comment;
            $comment->setContent($this['comments']->filterContentInput($data['content']));
            $comment->setUserId((int) $user->getId());
            $comment->setIp($this['request']->getClientIp());
            $comment->setCreated(new \DateTime);
            $comment->setPost($post);

            $approved_once = (boolean) $this->comments->query()->where(['user_id' => $user->getId(), 'status' => CommentInterface::STATUS_APPROVED])->first();
            $comment->setStatus($user->hasAccess('blog: skip comment approval') ? CommentInterface::STATUS_APPROVED : $user->hasAccess('blog: comment approval required once') && $approved_once ? CommentInterface::STATUS_APPROVED : CommentInterface::STATUS_PENDING);

            // check the max links rule
            if ($comment->getStatus() == CommentInterface::STATUS_APPROVED && $this->extension->getConfig('comments.maxlinks') <= preg_match_all('/<a [^>]*href/i', @$data['content'])) {
                $comment->setStatus(CommentInterface::STATUS_PENDING);
            }

            // check for spam
            $this['events']->dispatch('system.comment.spam_check', new CommentEvent($comment));

            $this->comments->save($comment, $data);

            $this['message']->info(__('Thanks for commenting!'));

            return $this->redirect($this['url']->route('@blog/id', ['id' => $post->getId()], true).'#comment-'.$comment->getId());

        } catch (Exception $e) {

            $this['message']->error($e->getMessage());

            return $this->redirect($this['url']->previous());

        } catch (\Exception $e) {

            $this['message']->error(__('Whoops, something went wrong!'));

            return $this->redirect($this['url']->previous());
        }
    }

    /**
     * @Route("/{id}", name="@blog/id")
     * @Response("blog/post/post.razr")
     */
    public function postAction($id = 0)
    {
        if (!$post = $this->posts->find($id) or $post->getStatus() != Post::STATUS_PUBLISHED or $post->getDate() > new \DateTime) {
            return $this['response']->create(__('Post not found!'), 404);
        }

        if (!$post->hasAccess($this['user'])) {
            return $this['response']->create(__('Unable to access this post!'), 403);
        }

        $user = $this['user'];
        $query = $this->comments->query()->where(['status = ?'], [CommentInterface::STATUS_APPROVED]);

        if ($user->isAuthenticated()) {
            $query->orWhere(function($query) use ($user) {
                $query->where(['status = ?', 'user_id = ?'], [CommentInterface::STATUS_PENDING, $user->getId()]);
            });
        }

        $this['db.em']->related($post, 'comments', $query);

        $post->setContent($this['content']->applyPlugins($post->getContent(), ['post' => $post, 'markdown' => $post->get('markdown')]));

        return ['head.title' => __($post->getTitle()), 'post' => $post, 'config' => $this->extension->getConfig()];
    }
}
