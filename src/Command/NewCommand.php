<?php

namespace Bolt\Installer\Command;

use Bolt\Installer\Exception\AbortException;
use Bolt\Installer\Manager\ComposerManager;
use Bolt\Installer\Urls;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command creates new Bolt projects for the given Bolt version.
 *
 * Based on Symfony Installer (c) Fabien Potencier <fabien@symfony.com>
 *
 * @author Christophe Coevoet <stof@notk.org>
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class NewCommand extends DownloadCommand
{
    protected $majorMinorVersion;
    protected $majorMinorPatchVersion;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Creates a new Bolt project.')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory where the new project will be created.')
            ->addArgument('version', InputArgument::OPTIONAL, 'The Bolt version to be installed (defaults to the latest stable version).', 'latest')
            ->addOption('flat', null, InputOption::VALUE_NONE, 'Install the "flat webroot" Bolt build.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $directory = rtrim(trim($input->getArgument('directory')), DIRECTORY_SEPARATOR);
        $this->version = trim($input->getArgument('version'));
        $this->projectDir = $this->fs->isAbsolutePath($directory) ? $directory : getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->projectName = basename($directory);
        $this->composerManager = new ComposerManager($this->projectDir);
        $this->useFlat = $input->getOption('flat');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this
                ->checkInstallerVersion()
                ->checkProjectName()
                ->checkPermissions()
                ->download()
                ->extract()
                ->cleanUp()
                ->dumpReadmeFile()
                ->updateParameters()
                ->updateComposerConfig()
                ->createGitIgnore()
                ->checkBoltRequirements()
                ->displayInstallationResult()
            ;
        } catch (AbortException $e) {
            aborted:

            $output->writeln('');
            $output->writeln('<error>Aborting download and cleaning up temporary directories.</error>');

            $this->cleanUp();

            return 1;
        } catch (\Exception $e) {
            // Guzzle can wrap the AbortException in a GuzzleException
            if ($e->getPrevious() instanceof AbortException) {
                goto aborted;
            }

            $this->cleanUp();
            throw $e;
        }

        return null;
    }

    /**
     * Removes all the temporary files and directories created to
     * download the project and removes Bolt-related files that don't make
     * sense in a proprietary project.
     *
     * @return $this
     */
    protected function cleanUp()
    {
        $this->fs->remove(dirname($this->downloadedFilePath));

        try {
            $licenseFile = [$this->projectDir . '/LICENSE'];
            $upgradeFiles = glob($this->projectDir . '/UPGRADE*.md');
            $changelogFiles = glob($this->projectDir . '/CHANGELOG*.md');

            $filesToRemove = array_merge($licenseFile, $upgradeFiles, $changelogFiles);
            $this->fs->remove($filesToRemove);
        } catch (\Exception $e) {
            // don't throw an exception in case any of the Bolt-related files cannot
            // be removed, because this is just an enhancement, not something mandatory
            // for the project
        }

        return $this;
    }

    /**
     * It displays the message with the result of installing Bolt
     * and provides some pointers to the user.
     *
     * @return $this
     */
    protected function displayInstallationResult()
    {
        if (empty($this->requirementsErrors)) {
            $this->output->writeln(sprintf(
                " <info>%s</info>  Bolt %s was <info>successfully installed</info>. Now you can:\n",
                defined('PHP_WINDOWS_VERSION_BUILD') ? 'OK' : '✔',
                $this->getInstalledBoltVersion()
            ));
        } else {
            $this->output->writeln(sprintf(
                " <comment>%s</comment>  Bolt %s was <info>successfully installed</info> but your system doesn't meet its\n" .
                "     technical requirements! Fix the following issues before executing\n" .
                "     your Bolt application:\n",
                defined('PHP_WINDOWS_VERSION_BUILD') ? 'FAILED' : '✕',
                $this->getInstalledBoltVersion()
            ));

            foreach ($this->requirementsErrors as $helpText) {
                $this->output->writeln(' * ' . $helpText);
            }

            //$checkFile = $this->isBolt3() ? 'bin/symfony_requirements' : 'app/check.php';
            //
            //$this->output->writeln(sprintf(
            //    " After fixing these issues, re-check Bolt requirements executing this command:\n\n" .
            //    "   <comment>php %s/%s</comment>\n\n" .
            //    " Then, you can:\n",
            //    $this->projectName, $checkFile
            //));
        }

        $consoleDir = ($this->isBolt3() ? 'app' : 'bin');
        $serverRunCommand = version_compare($this->version, '2.6.0', '>=') && extension_loaded('pcntl') ? 'server:start' : 'server:run';

        if ($this->getApplication()->isWeb()) {
            $webroot = $this->useFlat ? $this->projectDir : $this->projectDir . '/public';
            $siteUrl = 'http://' . $_SERVER['HTTP_HOST'];
            $section = str_replace('%DOCROOT%', $webroot, $this->getHtml('result.html'));
            $section = str_replace('%SITE_URL%', $siteUrl, $section);
            $this->output->writeln($section);

            return $this;
        }

        if ('.' !== $this->projectDir) {
            $this->output->writeln(sprintf(
                "    * Change your current directory to <comment>%s</comment>\n", $this->projectDir
            ));
        }

        $this->output->writeln(sprintf(
            "    * Configure your application in <comment>app/config/config.yml</comment> file.\n\n" .
            "    * Run your application:\n" .
            "        1. Execute the <comment>php %s/nut %s</comment> command.\n" .
            "        2. Browse to the <comment>http://0.0.0.0:8000</comment> URL.\n\n" .
            "    * Read the documentation at <comment>https://docs.bolt.cm</comment>\n",
            $consoleDir, $serverRunCommand
        ));

        return $this;
    }

    /**
     * Dump a basic README.md file.
     *
     * @return $this
     */
    protected function dumpReadmeFile()
    {
        $readmeContents = sprintf("%s\n%s\n\nA Bolt project created on %s.\n", $this->projectName, str_repeat('=', strlen($this->projectName)), date('F j, Y, g:i a'));
        try {
            $this->fs->dumpFile($this->projectDir . '/README.md', $readmeContents);
        } catch (\Exception $e) {
            // don't throw an exception in case the file could not be created,
            // because this is just an enhancement, not something mandatory
            // for the project
        }

        return $this;
    }

    /**
     * Updates the Bolt config.yml file to replace default configuration
     * values with better generated values.
     *
     * @return $this
     */
    protected function updateParameters()
    {
        $filename = $this->projectDir . '/app/config/config.yml';

        if (!is_writable($filename)) {
            if ($this->output->isVerbose()) {
                $this->output->writeln(sprintf(
                    " <comment>[WARNING]</comment> The value of the <info>secret</info> configuration option cannot be updated because\n" .
                    " the <comment>%s</comment> file is not writable.\n",
                    $filename
                ));
            }

            return $this;
        }

        $ret = str_replace('ThisTokenIsNotSoSecretChangeIt', $this->generateRandomSecret(), file_get_contents($filename));
        file_put_contents($filename, $ret);

        return $this;
    }

    /**
     * Updates the composer.json file to provide better values for some of the
     * default configuration values.
     *
     * @return $this
     */
    protected function updateComposerConfig()
    {
        parent::updateComposerConfig();
        $this->composerManager->updateProjectConfig([
            'name'        => $this->composerManager->createPackageName($this->projectName),
            'license'     => 'proprietary',
            'description' => null,
            'extra'       => ['branch-alias' => null],
        ]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDownloadedApplicationType()
    {
        return 'Bolt';
    }

    /**
     * {@inheritdoc}
     */
    protected function getRemoteFileUrl()
    {
        $queryParams = http_build_query(['php' => PHP_VERSION_ID, 'flat' => $this->useFlat]);

        if ($this->version === 'latest') {
            $client = $this->getGuzzleClient();
            $this->writeDebug(sprintf("<info> — Fetching %s</info>", Urls::REMOTE_LATEST));

            try {
                $response = $client->get(Urls::REMOTE_LATEST);
            } catch (ClientException $e) {
                throw new \RuntimeException($this->getRemoteVersionsExceptionMessage($e));
            }
            $majorMinorPatchVersion = $response->getBody()->getContents();
            $parts = explode('.', $majorMinorPatchVersion);
            if (count($parts) < 3) {
                throw new \RuntimeException(sprintf('There was an error getting latest version data from %s', Urls::REMOTE_LATEST));
            }
            $majorMinorVersion = $parts[0] . '.' . $parts[1];

            return sprintf(Urls::REMOTE_FILE, $majorMinorVersion, $majorMinorPatchVersion, $queryParams);
        }
        $this->getRemoteVersions();

        return sprintf(Urls::REMOTE_FILE, $this->majorMinorVersion, $this->majorMinorPatchVersion, $queryParams);
    }

    /**
     * @param \Exception $e
     *
     * @return \RuntimeException
     */
    private function getRemoteVersionsExceptionMessage(\Exception $e)
    {
        return new \RuntimeException(sprintf(
            "There was an error downloading %s version data from %s:\n%s",
            $this->getDownloadedApplicationType(),
            Urls::REMOTE_VERSIONS,
            $e->getMessage()
        ), null, $e);
    }

    /**
     * Determine best available version.
     */
    private function getRemoteVersions()
    {
        $this->output->writeln("\n Checking available versions...\n");
        $versionsCacheItem = $this->getCache()->getItem('json.remote_versions');
        if (!$versionsCacheItem->isHit()) {
            $client = $this->getGuzzleClient();
            $this->writeDebug(sprintf("<info> — Fetching %s</info>", Urls::REMOTE_VERSIONS));

            try {
                $response = $client->get(Urls::REMOTE_VERSIONS);
                $json = $response->getBody()->getContents();
                $versions = \GuzzleHttp\json_decode($json);
                $versionsCacheItem->set($json);
            } catch (ClientException $e) {
                throw new \RuntimeException($this->getRemoteVersionsExceptionMessage($e));
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException($this->getRemoteVersionsExceptionMessage($e));
            }
            $this->getCache()->save($versionsCacheItem);
        } else {
            $this->writeDebug(sprintf("<info> — Using cached version of %s</info>", Urls::REMOTE_VERSIONS));

            try {
                $versions = \GuzzleHttp\json_decode($versionsCacheItem->get());
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException($this->getRemoteVersionsExceptionMessage($e));
            }
        }

        $parts = explode('.', $this->version);
        $majorKey = $parts[0] . '.x';
        if (!property_exists($versions, $majorKey)) {
            throw new \RuntimeException(sprintf(
                "Requested version '%s' is not is a valid major release\n",
                $parts[0]
            ));
        }

        $available = $versions->$majorKey;
        $candidatesMinor = [];

        // X.Y versions
        foreach (get_object_vars($available) as $a => $v) {
            $parts = explode('.', $this->version);
            $majorMinorKey = $parts[0] . '.' . $parts[1];
            if (version_compare($a, $majorMinorKey, '>=')) {
                $candidatesMinor[] = $a;
            }
        }
        arsort($candidatesMinor);
        $majorMinor = reset($candidatesMinor);
        if ($majorMinor === false) {
            throw new \RuntimeException(sprintf('Unable to locate a version matching "%s" to download.', $this->version));
        }

        // X.Y.Z versions
        if (count($parts) > 2) {
            $majorMinorPatch = $this->version;
        } else {
            $candidatesPatch = [];
            foreach ($available->$majorMinor as $a => $v) {
                if (version_compare($v, $this->version, '>=')) {
                    $candidatesPatch[] = $v;
                }
            }
            arsort($candidatesPatch);
            $majorMinorPatch = reset($candidatesPatch);
            if ($majorMinorPatch === false) {
                throw new \RuntimeException(sprintf('Unable to locate a version matching "%s" to download.', $this->version));
            }
        }

        // Store them
        $this->majorMinorVersion = $majorMinor;
        $this->majorMinorPatchVersion = $majorMinorPatch;
    }
}
