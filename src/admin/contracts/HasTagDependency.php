<?php

namespace rokorolov\parus\admin\contracts;

/**
 * HasPresenter
 *
 * @author Roman Korolov <rokorolov@gmail.com>
 */
interface HasTagDependency
{
    public function getDependencyTagId();
}
