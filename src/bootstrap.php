<?php

/*
 * This file is part of the Sismo utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Silex\Application;
use Silex\Extension\TwigExtension;
use Silex\Extension\UrlGeneratorExtension;
use Silex\Extension\SymfonyBridgesExtension;
use Sismo\Sismo;
use Sismo\Project;
use Sismo\Commit;
use Sismo\Storage;
use Sismo\Builder;
use Symfony\Component\ClassLoader\UniversalClassLoader;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../vendor/silex/autoload.php';

$loader = new UniversalClassLoader();
$loader->registerNamespaces(array(
    'Sismo'   => __DIR__,
    'Symfony' => __DIR__.'/../vendor',
));
$loader->registerPrefixes(array(
    'Twig_' => __DIR__.'/../vendor/silex/vendor/twig/lib',
));
$loader->register();

$app = new Application();
$app->register(new SymfonyBridgesExtension());
$app->register(new UrlGeneratorExtension());
$app->register(new TwigExtension(), array(
    'twig.path'      => __DIR__.'/templates',
    'twig.options'   => array('debug' => true, 'strict_variables' => true),
    'twig.configure' => $app->protect(function ($twig) use ($app) {
        $twig->setCache($app['twig.cache.path']);
    }),
));

$app['data.path']   = getenv('SISMO_DATA_PATH') ?: getenv('HOME').'/.sismo/data';
$app['config.file'] = getenv('SISMO_CONFIG_PATH') ?: getenv('HOME').'/.sismo/config.php';
$app['build.path']  = $app->share(function ($app) { return $app['data.path'].'/build'; });
$app['db.path']     = $app->share(function ($app) {
    if (!is_dir($app['data.path'])) {
        mkdir($app['data.path'], 0777, true);
    }

    return $app['data.path'].'/sismo.db';
});
$app['twig.cache.path'] = $app->share(function ($app) { return $app['data.path'].'/cache'; });
$app['git.path']        = 'git';
$app['git.cmds']        = array();
$app['db.schema']       = <<<EOF
CREATE TABLE IF NOT EXISTS project (
    slug        TEXT,
    name        TEXT,
    repository  TEXT,
    branch      TEXT,
    command     BLOB,
    url_pattern TEXT,
    PRIMARY KEY (slug)
);

CREATE TABLE IF NOT EXISTS `commit` (
    slug          TEXT,
    sha           TEXT,
    date          TEXT,
    message       BLOB,
    author        TEXT,
    status        TEXT,
    output        BLOB,
    build_date    TEXT DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (slug, sha),
    CONSTRAINT slug FOREIGN KEY (slug) REFERENCES project(slug) ON DELETE CASCADE
);
EOF;

$app['db'] = $app->share(function () use ($app) {
    $db = new \SQLite3($app['db.path']);
    $db->busyTimeout(1000);
    $db->exec($app['db.schema']);

    return $db;
});

$app['storage'] = $app->share(function () use ($app) {
    return new Storage($app['db']);
});

$app['builder'] = $app->share(function () use ($app) {
    $process = new Process(sprintf('%s --version', $app['git.path']));
    if ($process->run() > 0) {
        throw new \RuntimeException(sprintf('The git binary cannot be found (%s).', $process->getErrorOutput()));
    }

    return new Builder($app['build.path'], $app['git.path'], $app['git.cmds']);
});

$app['sismo'] = $app->share(function () use ($app) {
    $sismo = new Sismo($app['storage'], $app['builder']);
    if (!is_file($app['config.file'])) {
        throw new \RuntimeException(sprintf("Looks like you forgot to define your projects.\nSismo looked into \"%s\".", $app['config.file']));
    }
    $projects = require $app['config.file'];

    if (null === $projects) {
        throw new \RuntimeException(sprintf('The "%s" configuration file must return an array of Projects (returns null).', $app['config.file']));
    }

    if (!is_array($projects)) {
        throw new \RuntimeException(sprintf('The "%s" configuration file must return an array of Projects (returns a non-array).', $app['config.file']));
    }

    foreach ($projects as $project) {
        if (!$project instanceof Project) {
            throw new \RuntimeException(sprintf('The "%s" configuration file must return an array of Project instances.', $app['config.file']));
        }

        $sismo->addProject($project);
    }

    return $sismo;
});

return $app;
