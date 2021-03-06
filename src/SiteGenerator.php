<?php

namespace Sereno;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Factory;
use Sereno\Contracts\Builder;
use Symfony\Component\Finder\SplFileInfo;

class SiteGenerator
{
    const DEFAULT_BUILDER = '__default';
    protected $app;
    protected $filesystem;
    protected $viewFactory;
    protected $builders = [];

    public function __construct(Application $app, Filesystem $filesystem, Factory $viewFactory)
    {
        $this->app = $app;
        $this->filesystem = $filesystem;
        $this->viewFactory = $viewFactory;
    }

    public function register(Builder $builder)
    {
        $this->app->verbose('     | Register Builder: '.get_class($builder));
        foreach ($builder->handledPatterns() as $pattern) {
            if (array_key_exists($pattern, $this->builders)) {
                $this->builders[$pattern][] = $builder;
            } else {
                $this->builders[$pattern] = [$builder];
            }
        }
    }

    public function build()
    {
        debug("\nSite Generator ::");
        debug('-------------------------');
        $groups = $this->groupFiles($this->getAllFiles());
        $data = $this->buildData($groups);

        $this->prepareOutputDirectory();
        $this->callBuilders($groups, $data);
    }

    protected function groupFiles(array $files) : array
    {
        $groups = [
            self::DEFAULT_BUILDER => [],
        ];

        foreach ($files as $file) {
            $name = $this->findBuilder($file);
            if (is_null($name)) {
                $groups[self::DEFAULT_BUILDER][] = $file;
            } else {
                if (! array_key_exists($name, $groups)) {
                    $groups[$name] = [];
                }

                $groups[$name][] = $file;
            }
        }

        return $groups;
    }

    protected function getAllFiles() : array
    {
        debug('=> Search files...');
        $files = [];
        $dirs = config('sereno.directory');

        foreach ($dirs as $dir) {
            $path = root_dir($dir);

            if ($this->filesystem->exists($path)) {
                if ($this->filesystem->isDirectory($path)) {
                    $files = array_merge($files, $this->filesystem->allFiles($path));
                } else {
                    $files = array_merge($files, [new SplFileInfo($path, root_dir(), str_replace(root_dir(), '', $path))]);
                }
            }
        }

        $keys = [];
        $ignored = (array) config('sereno.ignore');

        $files = array_filter(
            $files,
            function (SplFileInfo $file) use (&$keys, $ignored) {
                foreach ($ignored as $prefix) {
                    if (str_is(root_dir($prefix), $file->getRealPath())) {
                        debug('  -> <error>Ignore:</error> '.$file->getRelativePathname().', <info>Rule:</info> '.$prefix);

                        return false;
                    }
                }

                if (! array_key_exists($file->getRealPath(), $keys)) {
                    $keys[$file->getRealPath()] = true;
                    debug('  -> <info>Select:</info> '.str_replace(root_dir(), '', $file->getRealPath()));

                    return true;
                }

                debug('  -> <error>Duplicate:</error> '.$file->getRelativePathname());

                return false;
            }
        );

        return $files;
    }

    protected function buildData(array $groups) : array
    {
        $data = [];

        foreach ($groups as $name => $files) {
            foreach ($this->builders[$name] as $builder) {
                /* @var Builder $builder */
                $data = $builder->data($files, $data);
            }
        }

        return $data;
    }

    protected function callBuilders($groups, $data)
    {
        foreach ($groups as $name => $files) {
            foreach ($this->builders[$name] as $builder) {
                debug(PHP_EOL.'=> Build: <question> '.$name.' </question> <info>Use: '.get_class($builder).'</info>');
                /* @var Builder $builder */
                $builder->build($files, $data);
            }
        }
    }

    protected function findBuilder(SplFileInfo $file)
    {
        foreach ($this->builders as $pattern => $builder) {
            $path = trim(str_replace(root_dir(), '', $file->getRealPath()), DIRECTORY_SEPARATOR);
            if (starts_with($pattern, '/') and preg_match($pattern, $path)) {
                return $pattern;
            }

            if (str_is($pattern, $path) or str_is($pattern, $path)) {
                return $pattern;
            }
        }
    }

    public function getResourcesDirectory(): string
    {
        return $this->resourcesDirectory;
    }

    public function setResourcesDirectory(string $resourcesDirectory)
    {
        $this->resourcesDirectory = $resourcesDirectory;
    }

    protected function prepareOutputDirectory()
    {
        if (! $this->filesystem->exists(public_dir())) {
            $this->filesystem->makeDirectory(public_dir(), 0755, true);
        }

        $this->filesystem->cleanDirectory(public_dir());
    }
}
