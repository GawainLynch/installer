<?php

namespace Bolt\Installer\Command;

use Bolt\Installer\Urls;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * This command is inspired by the self-update command included
 * in the PHP-CS-Fixer library.
 *
 * Based on Symfony Installer (c) Fabien Potencier <fabien@symfony.com>
 *
 * @link https://github.com/fabpot/PHP-CS-Fixer/blob/master/Symfony/CS/Console/Command/SelfUpdateCommand.php.
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Stephane PY <py.stephane1@gmail.com>
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class SelfUpdateCommand extends DownloadCommand
{
    /** @var string The temp dir */
    private $tempDir;
    /** @var string The URL where the latest installer version can be downloaded */
    private $remoteInstallerFile;
    /** @var string The filepath of the installer currently installed in the local machine */
    private $currentInstallerFile;
    /** @var string The filepath of the new installer downloaded to replace the current installer */
    private $newInstallerFile;
    /** @var string The filepath of the backup of the current installer in case a rollback is performed */
    private $currentInstallerBackupFile;
    /** @var bool Flag which indicates that, in case of a rollback, it's safe to restore the installer backup because
     *            it corresponds to the most recent version */
    private $restorePreviousInstaller;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('self-update')
            ->setAliases(['selfupdate'])
            ->addOption('force-update', 'f', InputOption::VALUE_NONE, 'It updates the installer to the latest available version without checking if it\'s older or newer than the locally installed version.')
            ->setDescription('Update the Bolt Installer to the latest version.')
            ->setHelp('The <info>%command.name%</info> command updates the installer to the latest available version.')
        ;
    }

    /**
     * The self-update command is only available when using the installer via the PHAR file.
     *
     * @return bool Whether the command is enabled
     */
    public function isEnabled()
    {
        return 'phar://' === substr(__DIR__, 0, 7);
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->remoteInstallerFile = Urls::INSTALLER_FILE;
        $this->currentInstallerFile = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $this->tempDir = sys_get_temp_dir();
        $this->currentInstallerBackupFile = basename($this->currentInstallerFile, '.phar') . '-backup.phar';
        $this->newInstallerFile = $this->tempDir . '/' . basename($this->currentInstallerFile, '.phar') . '-temp.phar';
        $this->restorePreviousInstaller = false;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $forceUpdate = true === $input->getOption('force-update');
        if (!$forceUpdate && $this->isInstallerUpdated()) {
            $this->output->writeln(sprintf(' Bolt Installer is <info>already updated</info> to the latest version (%s).', $this->latestInstallerVersion));

            return 0;
        }

        $this->output->writeln(sprintf(' <info>Updating</info> Bolt Installer to <info>%s</info> version', $this->latestInstallerVersion));

        try {
            $this
                ->downloadNewVersion()
                ->checkNewVersionIsValid()
                ->backupCurrentVersion()
                ->replaceCurrentVersionbyNewVersion()
                ->cleanUp()
            ;
        } catch (IOException $e) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln($e->getMessage());
            }

            throw new \RuntimeException(sprintf(
                "The installer couldn't be updated, probably because of a permissions issue.\n" .
                "Try to execute the command again with super user privileges:\n" .
                "  sudo %s\n",
                $this->getExecutedCommand()
            ));
        } catch (\Exception $e) {
            $this->rollback();

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln($e->getMessage());
            }

            return 1;
        }

        return 0;
    }

    /**
     * Downloads the new version of the Bolt installer.
     *
     * @return $this
     */
    private function downloadNewVersion()
    {
        // check for permissions in local filesystem before start downloading files
        if (!is_writable($this->currentInstallerFile)) {
            throw new IOException('Bolt Installer update failed: the "' . $this->currentInstallerFile . '" file could not be written');
        }

        if (!is_writable($this->tempDir)) {
            throw new IOException('Bolt Installer update failed: the "' . $this->tempDir . '" directory used to download files temporarily could not be written');
        }

        if (false === $newInstaller = $this->getUrlContents($this->remoteInstallerFile)->getContents()) {
            throw new \RuntimeException('The new version of the Bolt Installer couldn\'t be downloaded from the server.');
        }

        $newInstallerPermissions = $this->currentInstallerFile ? fileperms($this->currentInstallerFile) : 0777 & ~umask();
        $this->fs->dumpFile($this->newInstallerFile, $newInstaller, $newInstallerPermissions);

        return $this;
    }

    /**
     * Checks if the new version is valid.
     *
     * @return $this
     */
    private function checkNewVersionIsValid()
    {
        // creating a Phar instance for an existing file is not allowed
        // when the Phar extension is in readonly mode
        if (!ini_get('phar.readonly')) {
            // test the phar validity
            $phar = new \Phar($this->newInstallerFile);

            // free the variable to unlock the file
            unset($phar);
        }

        return $this;
    }

    /**
     * Does a backup of the current version of the Bolt installer.
     *
     * @return $this
     */
    private function backupCurrentVersion()
    {
        $this->writeDebug(sprintf("<info> — Backing up %s to %s</info>", $this->currentInstallerFile, $this->currentInstallerBackupFile));
        $this->fs->copy($this->currentInstallerFile, $this->currentInstallerBackupFile, true);
        $this->restorePreviousInstaller = true;

        return $this;
    }

    /**
     * Replaces the currenct version of the Bolt installer with the new one.
     *
     * @return $this
     */
    private function replaceCurrentVersionbyNewVersion()
    {
        $this->writeDebug(sprintf("<info> — Replacing %s to %s</info>", $this->newInstallerFile, $this->currentInstallerFile));
        $this->fs->copy($this->newInstallerFile, $this->currentInstallerFile, true);

        return $this;
    }

    /**
     * Removes the temporary used files.
     */
    private function cleanUp()
    {
        $this->fs->remove([$this->currentInstallerBackupFile, $this->newInstallerFile]);
    }

    /**
     * Restores the previously installed version of the Bolt installer.
     */
    private function rollback()
    {
        $this->output->writeln([
            '',
            '<error>There was an error while updating the installer.</error>',
            'The previous Bolt Installer version has been restored.',
            '',
        ]);

        $this->fs->remove($this->newInstallerFile);

        if ($this->restorePreviousInstaller) {
            $this->fs->copy($this->currentInstallerBackupFile, $this->currentInstallerFile, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getDownloadedApplicationType()
    {
        return 'Bolt Installer';
    }

    /**
     * {@inheritdoc}
     */
    protected function getRemoteFileUrl()
    {
        return Urls::INSTALLER_FILE;
    }
}
