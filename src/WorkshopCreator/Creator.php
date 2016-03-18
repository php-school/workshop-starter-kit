<?php

namespace WorkshopCreator;

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Script\Event;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class WorkshopInstaller
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 */
class Creator
{

    /**
     * @var array
     */
    private static $devDependencies = [
        'composer/composer',
    ];

    /**
     * @param Event $event
     */
    public static function install(Event $event)
    {
        $io = $event->getIO();
        $composer = $event->getComposer();

        // Get composer.json
        $composerFile = Factory::getComposerFile();
        $json = new JsonFile($composerFile);

        $composerDefinition = $json->read();

        $io->write("<info>Setting up Workshop!</info>");

        // Get root package
        $rootPackage = $composer->getPackage();
        while ($rootPackage instanceof AliasPackage) {
            $rootPackage = $rootPackage->getAliasOf();
        }

        //set composer project name
        $projectName = $io->askAndValidate(
            "\n  <question> Name for composer package (eg php-school/learn-you-php)? </question> ",
            function ($answer) {
                if (!preg_match('/[a-z0-9-]+\/[a-z0-9-]+/', $answer)) {
                    throw new Exception('Package name must be in the form: vendor/package. Lowercase, alphanumerical, may include dashes and be must slash separated.');
                }
                return $answer;
            },
            3
        );
        $composerDefinition['name'] = $projectName;

        $projectDescription = $io->ask("\n  <question> Workshop description? </question> ");
        $composerDefinition['description'] = $projectDescription;

        //set namespaces
        $namespace = $io->askAndValidate(
            "\n  <question> Namespace for Workshop (eg PhpSchool\\LearnYouPhp)? </question> ",
            function ($answer) {
                if (!preg_match('/^(\\\\{1,2})?\w+\\\\{1,2}(?:\w+\\\\{0,2})+$/', $answer)) {
                    throw new Exception('Package name must be a valid PHP namespace');
                }
                return $answer;
            },
            3
        );
        $composerDefinition['autoload']['psr-4'][sprintf('%s\\', trim($namespace, '\\'))] = 'src/';
        $composerDefinition['autoload-dev']['psr-4'][sprintf('%sTest\\', trim($namespace, '\\'))] = 'test';

        //set bin name
        $binaryName = $io->askAndValidate(
            "\n  <question> Binary name (program users will run, eg learnyouphp)? </question> ",
            function ($answer) {
                if (!preg_match('/[a-z0-9-]+/', $answer)) {
                    throw new Exception('Binary name must lowercase, alphanumerical and can include dashed');
                }
                return $answer;
            }
        );
        $composerDefinition['bin'] = [sprintf('bin/%s', $binaryName)];
        rename(__DIR__ . '/../../bin/my-workshop', __DIR__ . '/../../bin/' . $binaryName);
        // House keeping
        $io->write("<info>Remove installer</info>");

        //remove dev deps
        foreach (self::$devDependencies as $devDependency) {
            unset($composerDefinition['require-dev'][$devDependency]);
        }

        // Remove installer scripts, only need to do this once
        unset($composerDefinition['scripts']['pre-update-cmd']);
        unset($composerDefinition['scripts']['pre-install-cmd']);
        if (empty($composerDefinition['scripts'])) {
            unset($composerDefinition['scripts']);
        }

        // Remove installer script autoloading rules
        unset($composerDefinition['autoload']['psr-4']['WorkshopCreator\\']);

        // Update composer definition
        $json->write($composerDefinition);

        $io->write("<info>Removing Workshop installer classes</info>");
        self::recursiveRmdir(__DIR__);
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $directory
     */
    private static function recursiveRmdir($directory)
    {
        $rdi = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $filename => $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($filename);
                continue;
            }
            unlink($filename);
        }
        rmdir($directory);
    }
}
