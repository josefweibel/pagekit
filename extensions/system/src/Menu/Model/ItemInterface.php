<?php

namespace Pagekit\Menu\Model;

interface ItemInterface
{
    /** @var int Item deactivated. */
    const STATUS_DEACTIVATED = 0;

    /** @var int Item active. */
    const STATUS_ACTIVE = 1;

    /**
     * @return string
     */
    public function getId();

    /**
     * @return string
     */
    public function getName();

    /**
     * @return string
     */
    public function getUrl();

    /**
     * @return mixed
     */
    public function getParentId();

    /**
     * Returns the items attributes
     *
     * @return array
     */
    public function getAttributes();

    /**
     * @param string $name
     * @param mixed $default
     */
    public function getAttribute($name, $default = null);

    /**
     * Gets item's data array
     */
    public function getData();

    /**
     * Gets a data value
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($key, $default = null);

    /**
     * @return MenuInterface
     */
    public function getMenu();

    /**
     * @return string
     */
    public function __toString();
}
