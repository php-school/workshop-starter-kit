<?php

namespace WorkshopCreator;

use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Script\Event;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

class Creator
{
    /**
     * @var string
     */
    private static $logo = '
   ____    __  __  ____        ____            __                    ___
  /\  _`\ /\ \/\ \/\  _`\     /\  _`\         /\ \                  /\_ \
  \ \ \L\ \ \ \_\ \ \ \L\ \   \ \,\L\_\    ___\ \ \___     ___    __\//\ \
   \ \ ,__/\ \  _  \ \ ,__/    \/_\__ \   /\'___\ \  _ `\  / __`\ / __`\ \ \
    \ \ \/  \ \ \ \ \ \ \/       /\ \L\ \/\ \__/\ \ \ \ \/\ \L\ /\ \L\ \_\ \_
     \ \_\   \ \_\ \_\ \_\       \ `\____\ \____\\ \_\ \_\ \____\ \____/\____\
      \/_/    \/_/\/_/\/_/        \/_____/\/____/ \/_/\/_/\/___/ \/___/\/____/';

    /**
     * @var array<string>
     */
    private static $devDependencies = [
        'composer/composer',
    ];

    /**
     * @var IOInterface
     */
    private static $io;

    /**
     * @var array{
     *     autoload: array{psr-4: array<string, string>},
     *     autoload-dev: array{psr-4: array<string, string>},
     *     require-dev: array<string, string>,
     *     scripts: array<string, string>
     * }
     */
    private static $composerDefinition;

    /**
     * @var string
     */
    private static $binaryName;

    /**
     * Probably shouldn't do this
     *
     * @param IOInterface $io
     */
    private static function configureIo(IOInterface $io): void
    {
        $refP = new \ReflectionProperty(get_class($io), 'output');
        $refP->setAccessible(true);
        $output = $refP->getValue($io);

        /** @var OutputInterface $output */
        $output->getFormatter()
            ->setStyle('magenta', new OutputFormatterStyle('magenta', 'default', ['bold']));

        self::$io = $io;
    }

    /**
     * @param Event $event
     */
    public static function install(Event $event): void
    {
        self::configureIo($event->getIO());
        self::$io->write(sprintf('<magenta>%s</magenta>', self::$logo));
        self::$io->write("");

        // Get composer.json
        $composerFile = Factory::getComposerFile();
        $json = new JsonFile($composerFile);

        /** @var array{
         *     autoload: array{psr-4: array<string, string>},
         *     autoload-dev: array{psr-4: array<string, string>},
         *     require-dev: array<string, string>,
         *     scripts: array<string, string>
         * } $definition */
        $definition = $json->read();
        self::$composerDefinition = $definition;

        self::$io->write("<info>Setting up Workshop!</info>");

        // Get root package
        $rootPackage = $event->getComposer()->getPackage();
        while ($rootPackage instanceof AliasPackage) {
            $rootPackage = $rootPackage->getAliasOf();
        }

        $composerName = self::$io->askAndValidate(
            "\n  <magenta> Name for composer package (eg php-school/learn-you-php)? </magenta> ",
            function ($answer) {
                if (!preg_match('/[a-z0-9-]+\/[a-z0-9-]+/', $answer)) {
                    $message  = 'Package name must be in the form: vendor/package. Lowercase, alphanumerical, may ';
                    $message .= 'include dashes and be must slash separated.';
                    throw new Exception($message);
                }
                return $answer;
            },
            3
        );

        /** @var string $projectTitle */
        $projectTitle = self::$io->ask("\n  <magenta> Workshop title? </magenta> ");
        $projectDescription = self::$io->ask("\n  <magenta> Workshop description? </magenta> ");

        /** @var string $namespace */
        $namespace = self::$io->askAndValidate(
            "\n  <magenta> Namespace for Workshop (eg PhpSchool\\LearnYouPhp)? </magenta> ",
            function ($answer) {
                if (!preg_match('/^(\\\\{1,2})?\w+\\\\{1,2}(?:\w+\\\\{0,2})+$/', $answer)) {
                    throw new Exception('Package name must be a valid PHP namespace');
                }
                return $answer;
            },
            3
        );

        /** @var string $binary */
        $binary = self::$io->askAndValidate(
            "\n  <magenta> Binary name (program users will run, eg learnyouphp)? </magenta> ",
            function ($answer): string {
                if (!preg_match('/[a-z0-9-]+/', $answer)) {
                    throw new Exception('Binary name must lowercase, alphanumerical and can include dashed');
                }
                return $answer;
            }
        );
        self::$binaryName = $binary;

        self::$io->write('');

        self::runTask(
            'Configuring project name and description',
            function () use ($composerName, $projectDescription, $projectTitle) {
                self::$composerDefinition['name'] = $composerName;
                self::$composerDefinition['description'] = $projectDescription;

                $bootstrap = file_get_contents(__DIR__ . '/../../app/bootstrap.php');
                $bootstrap = str_replace('___PROJECT_TITLE___', $projectTitle, (string) $bootstrap);
                file_put_contents(__DIR__ . '/../../app/bootstrap.php', $bootstrap);
            }
        );

        self::runTask('Configuring autoload and namespaces', function () use ($namespace) {
            self::$composerDefinition['autoload']['psr-4'][sprintf('%s\\', trim($namespace, '\\'))] = 'src/';
            self::$composerDefinition['autoload-dev']['psr-4'][sprintf('%sTest\\', trim($namespace, '\\'))] = 'test';
        });

        self::runTask('Updating binary config', function () {
            self::$composerDefinition['bin'] = [sprintf('bin/%s', self::$binaryName)];
            rename(__DIR__ . '/../../bin/my-workshop', __DIR__ . '/../../bin/' . self::$binaryName);
        });

        self::runTask('Removing dev dependencies', function () {
            foreach (self::$devDependencies as $devDependency) {
                unset(self::$composerDefinition['require-dev'][$devDependency]);
            }
        });

        self::runTask('Removing installer autoload and script config', function () {
            unset(self::$composerDefinition['scripts']['pre-update-cmd']);
            unset(self::$composerDefinition['scripts']['pre-install-cmd']);
            unset(self::$composerDefinition['scripts']['post-update-cmd']);
            unset(self::$composerDefinition['scripts']['post-install-cmd']);
            if (empty(self::$composerDefinition['scripts'])) {
                unset(self::$composerDefinition['scripts']);
            }
            unset(self::$composerDefinition['autoload']['psr-4']['WorkshopCreator\\']);
        });

        self::runTask('Writing Composer.json file', function () use ($json) {
            $json->write(self::$composerDefinition);
        });

        self::runTask('Removing Workshop installer classes', function () {
            self::recursiveRmdir(__DIR__);
        });
    }

    private static function runTask(string $message, callable $task): void
    {
        self::$io->write(sprintf('<info> - [ ] %s</info>', $message), false);
        $task();
        self::$io->overwrite(sprintf('<info> - [x] %s</info>', $message), true);
    }

    public static function summary(Event $event): void
    {
        self::$io->write(
            sprintf("\n\n<magenta>Run your workshop with: %s bin/%s</magenta>", basename(PHP_BINARY), self::$binaryName)
        );
        self::$io->write("\n<magenta>DONE - Now build and educate!</magenta>\n");
    }

    /**
     * Recursively remove a directory.
     */
    private static function recursiveRmdir(string $directory): void
    {
        $rdi = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $filename => $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            /** @var string $filename */
            if ($fileInfo->isDir()) {
                rmdir($filename);
                continue;
            }
            unlink($filename);
        }
        rmdir($directory);
    }
}
