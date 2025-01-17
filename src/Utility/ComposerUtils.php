<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 Core Patches.
 *
 * (c) Gilbertsoft LLC (gilbertsoft.org)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GsTYPO3\CorePatches\Utility;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Semver\Semver;
use GsTYPO3\CorePatches\Config;
use GsTYPO3\CorePatches\Config\PreferredInstall;
use GsTYPO3\CorePatches\Exception\CommandExecutionException;
use GsTYPO3\CorePatches\Exception\InvalidResponseException;
use GsTYPO3\CorePatches\Exception\NoPatchException;
use GsTYPO3\CorePatches\Exception\UnexpectedResponseException;
use GsTYPO3\CorePatches\Exception\UnexpectedValueException;
use GsTYPO3\CorePatches\Gerrit\RestApi;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

final class ComposerUtils
{
    /**
     * @var string
     */
    private const EXTRA = 'extra';

    private Composer $composer;

    private IOInterface $io;

    private Config $config;

    private JsonFile $configFile;

    private Application $application;

    private RestApi $gerritRestApi;

    private PatchUtils $patchUtils;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->config = new Config();
        $this->configFile = new JsonFile(Factory::getComposerFile(), null, $this->io);
        $this->application = new Application();
        $this->application->setAutoExit(false);

        $this->gerritRestApi = new RestApi(Factory::createHttpDownloader($this->io, $this->composer->getConfig()));
        $this->patchUtils = new PatchUtils($this->composer, $this->io);
    }

    /**
     * @param array<string, array<string, string>> $patches
     */
    private function addPatchesToConfigFile(array $patches): void
    {
        $configPatches = $this->config->load($this->configFile)->getPatches();

        foreach ($patches as $packgeName => $packagePatches) {
            $configPatches->add($packgeName, $packagePatches);
        }

        $this->config->save($this->configFile);
    }

    /**
     * @param array<int, string> $packages
     */
    private function addAppliedChange(
        int $numericId,
        array $packages,
        bool $includeTests = false,
        string $destination = '',
        int $revision = -1
    ): void {
        $changes = $this->config->load($this->configFile)->getChanges();

        if ($changes->has($numericId)) {
            // the package is already listed, skip addition
            return;
        }

        $changes->add($numericId, $packages, $includeTests, $destination, $revision);

        $this->config->save($this->configFile);
    }

    private function addPreferredInstallChanged(string $packageName): void
    {
        $preferredInstallChanged = $this->config->load($this->configFile)->getPreferredInstallChanged();

        if ($preferredInstallChanged->has($packageName)) {
            // the package is already listed, skip addition
            return;
        }

        $preferredInstallChanged->add($packageName);
        $this->config->save($this->configFile);
    }

    private function removePreferredInstallChanged(string $packageName): void
    {
        $preferredInstallChanged = $this->config->load($this->configFile)->getPreferredInstallChanged();

        if (!$preferredInstallChanged->has($packageName)) {
            // the package is not listed, skip removal
            return;
        }

        $preferredInstallChanged->remove($packageName);
        $this->config->save($this->configFile);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getPatches(): array
    {
        $config = $this->configFile->read();

        /** @noRector \Rector\EarlyReturn\Rector */
        if (
            !is_array($config)
            || !is_array($config[self::EXTRA] ?? null)
            || !is_array($config[self::EXTRA]['patches'] ?? null)
        ) {
            return [];
        }

        return $config[self::EXTRA]['patches'];
    }

    /**
     * @param array<int, string> $changeIds
     * @param array<int, string> $affectedPackages
     */
    private function createPatches(
        array $changeIds,
        string $destination,
        bool $includeTests,
        array &$affectedPackages
    ): int {
        // Process change IDs
        $patchesCount = 0;

        foreach ($changeIds as $changeId) {
            $this->io->write(sprintf('<info>Creating patches for change <comment>%s</comment></info>', $changeId));

            try {
                $subject = $this->gerritRestApi->getSubject($changeId);
                $this->io->write(sprintf('  - Subject is <comment>%s</comment>', $subject));

                $numericId = $this->gerritRestApi->getNumericId($changeId);
                $this->io->write(sprintf('  - Numeric ID is <comment>%s</comment>', $numericId));
            } catch (UnexpectedResponseException | InvalidResponseException | UnexpectedValueException $th) {
                $this->io->writeError('<warning>Error getting change from Gerrit</warning>');
                $this->io->writeError(sprintf(
                    '<warning>%s</warning>',
                    $th->getMessage()
                ), true, IOInterface::VERBOSE);

                continue;
            }

            try {
                $patches = $this->patchUtils->create(
                    $numericId,
                    $subject,
                    $this->gerritRestApi->getPatch($changeId),
                    $destination,
                    $includeTests
                );
            } catch (NoPatchException $noPatchException) {
                $this->io->writeError('<warning>No patches saved for this change</warning>');

                continue;
            }

            $patchesCreated = count($patches);

            $this->io->write(sprintf(
                '  - Change saved to <comment>%d</comment> patch%s',
                $patchesCreated,
                $patchesCreated === 1 ? '' : 'es'
            ));

            // Adding patches to configuration
            $this->io->write('  - Adding patches to <info>composer.json</info>');
            $this->addPatchesToConfigFile($patches);
            $this->addAppliedChange($numericId, array_keys($patches), $includeTests, $destination);

            $affectedPackages = [...$affectedPackages, ...array_keys($patches)];

            $patchesCount += $patchesCreated;
        }

        return $patchesCount;
    }

    private function setSourceInstall(string $packageName): void
    {
        /*
        $currentValue = $this->getPreferredInstall();

        if (isset($currentValue[$packageName]) && $currentValue[$packageName] === 'source') {
            // source install for this package is already configured, skip it
            return;
        }

        $this->configSource->addConfigSetting(
            'preferred-install.' . $packageName,
            'source'
        );
        */

        $preferredInstall = $this->config->load($this->configFile)->getPreferredInstall();

        if ($preferredInstall->has($packageName, PreferredInstall::METHOD_SOURCE)) {
            // source install for this package is already configured, skip it
            return;
        }

        $preferredInstall->add($packageName, PreferredInstall::METHOD_SOURCE);

        $this->config->save($this->configFile);

        $this->addPreferredInstallChanged($packageName);
    }

    private function uninstallPackage(PackageInterface $package): ?PromiseInterface
    {
        return $this->composer->getInstallationManager()->uninstall(
            $this->composer->getRepositoryManager()->getLocalRepository(),
            new UninstallOperation($package)
        );
    }

    /**
     * @param array<string, array<string, string>> $patches
     */
    private function removePatchesFromConfigFile(array $patches): void
    {
        $configPatches = $this->config->load($this->configFile)->getPatches();

        foreach ($patches as $packgeName => $packagePatches) {
            $configPatches->remove($packgeName, $packagePatches);
        }

        $this->config->save($this->configFile);
    }

    private function unsetSourceInstall(string $packageName): void
    {
        $config = $this->config->load($this->configFile);
        $packages = $config->getPreferredInstallChanged();
        $preferredInstall = $config->getPreferredInstall();

        if (!$packages->has($packageName)) {
            // source was not set by this package, skip removal
            return;
        }

        $preferredInstall->remove($packageName);

        $this->config->save($this->configFile);

        $this->removePreferredInstallChanged($packageName);
    }

    private function removeAppliedChange(int $numericId): void
    {
        $changes = $this->config->load($this->configFile)->getChanges();

        if (!$changes->has($numericId)) {
            // the package is not listed, skip removal
            return;
        }

        $changes->remove($numericId);

        $this->config->save($this->configFile);
    }

    /**
     * @return array<int, string>
     */
    private function getPackagesForChange(int $changeId): array
    {
        $changePackages = [];

        $patches = $this->getPatches();

        foreach ($patches as $packageName => $packagePatches) {
            foreach ($packagePatches as $packagePatch) {
                if ($this->patchUtils->patchIsPartOfChange($packagePatch, $changeId)) {
                    $changePackages[] = $packageName;
                }
            }
        }

        return $changePackages;
    }

    private function askRemoval(int $changeId): bool
    {
        return $this->io->askConfirmation(
            sprintf(
                '<info>The change %d appears to be present in the version being installed or updated.' .
                ' Should the patch for this change be removed?</info> [<comment>Y,n</comment>] ',
                $changeId
            ),
            true
        );
    }

    /**
     * @throws CommandExecutionException
     */
    private function checkCommandResult(int $resultCode, int $expectedCode, string $errorMessage): void
    {
        if ($resultCode !== $expectedCode) {
            throw new CommandExecutionException($errorMessage, $resultCode);
        }
    }

    /**
     * @param  array<int, string> $changeIds
     * @return int                The number of patches added
     */
    public function addPatches(array $changeIds, string $destination, bool $includeTests): int
    {
        $affectedPackages = [];
        $patchesCount = $this->createPatches($changeIds, $destination, $includeTests, $affectedPackages);

        if ($patchesCount > 0) {
            // Reconfigure preferred-install for packages if changes of tests are patched too
            if ($includeTests) {
                $this->io->write(
                    '<info>Reconfiguring <comment>preferred-install</comment> to <comment>source</comment></info>'
                );

                $affectedPackages = array_unique($affectedPackages);
                $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
                $promises = [];

                foreach ($packages as $package) {
                    $packageName = $package->getName();
                    if (in_array($packageName, $affectedPackages, true)) {
                        $this->io->write(sprintf(
                            '  - <info>%s</info>',
                            $packageName
                        ));

                        $this->setSourceInstall($packageName);

                        // Uninstall package to force reinstall from sources
                        $promises[] = $this->uninstallPackage($package);

                        // Remove package from the array
                        $affectedPackages = array_filter(
                            $affectedPackages,
                            fn ($value): bool => $value !== $packageName
                        );
                    }
                }

                // Let Composer remove the packages marked for uninstall
                $promises = array_filter($promises);
                if ($promises !== []) {
                    $this->composer->getLoop()->wait($promises);
                }

                // Show a warning for patches of missing packages
                if ($affectedPackages !== []) {
                    $this->io->write('<warning>Patches for non-existent packages found, these are:</warning>');
                    foreach ($affectedPackages as $affectedPackage) {
                        $this->io->write(sprintf(
                            '  - <info>%s</info>',
                            $affectedPackage
                        ));
                    }
                }
            }
        } else {
            $this->io->write('<warning>No patches created</warning>');
        }

        return $patchesCount;
    }

    /**
     * @param  array<int, string> $changeIds
     * @return int                The number of patches removed
     */
    public function removePatches(array $changeIds, bool $skipUnistall = false): int
    {
        // Process change IDs
        $appliedChanges = $this->config->load($this->configFile)->getChanges();
        $numericIds = [];

        foreach ($changeIds as $changeId) {
            $this->io->write(sprintf(
                '<info>Collecting information for change <comment>%s</comment></info>',
                $changeId
            ));

            try {
                $numericId = $this->gerritRestApi->getNumericId($changeId);
                $this->io->write(sprintf('  - Numeric ID is <comment>%s</comment>', $numericId));
            } catch (UnexpectedResponseException | InvalidResponseException | UnexpectedValueException $th) {
                $this->io->writeError('<warning>Error getting numeric ID</warning>');
                $this->io->writeError(sprintf(
                    '<warning>%s</warning>',
                    $th->getMessage()
                ), true, IOInterface::VERBOSE);

                continue;
            }

            // Check if change was previously added by this plugin or skip
            if (!$appliedChanges->has($numericId)) {
                $this->io->writeError('<warning>Change was not applied by this plugin, skipping</warning>');

                continue;
            }

            $numericIds[] = $numericId;
        }

        // Remove patches
        $patchesCount = 0;
        $patches = $this->getPatches();

        if ($patches !== [] && $numericIds !== []) {
            $this->io->write('<info>Removing patches</info>');

            $patchesToRemove = $this->patchUtils->remove($numericIds, $patches);

            $this->io->write('  - Removing patches from <info>composer.json</info>');
            $this->removePatchesFromConfigFile($patchesToRemove);

            if ($patchesToRemove !== []) {
                // Revert source install if the last patch was removed
                $patches = $this->getPatches();

                foreach ($patchesToRemove as $packageName => $packagePatches) {
                    if (!isset($patches[$packageName])) {
                        $this->io->write(sprintf(
                            '  - Reconfiguring <info>preferred-install</info> for package <info>%s</info>',
                            $packageName
                        ));
                        $this->unsetSourceInstall($packageName);
                    }

                    $patchesCount += count($packagePatches);
                }

                if (!$skipUnistall) {
                    // Uninstall packages with removed patches
                    $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
                    $promises = [];

                    foreach ($packages as $package) {
                        $packageName = $package->getName();
                        if (isset($patchesToRemove[$packageName])) {
                            $this->io->write(sprintf(
                                '<info>Removing package %s so that it can be reinstalled without the patch.</info>',
                                $packageName
                            ));
                            $promises[] = $this->uninstallPackage($package);
                        }
                    }

                    // Let Composer remove the packages marked for uninstall
                    $promises = array_filter($promises);
                    if ($promises !== []) {
                        $this->composer->getLoop()->wait($promises);
                    }
                }
            }

            // Remove patches from applied changes
            foreach ($numericIds as $numericId) {
                $this->removeAppliedChange($numericId);
            }
        } else {
            $this->io->write('<warning>No patches removed</warning>');
        }

        return $patchesCount;
    }

    /**
     * @param  array<int, string> $changeIds
     * @return int                The number of patches added
     */
    public function updatePatches(array $changeIds): int
    {
        $patchesCount = 0;
        $affectedPackages = [];

        $appliedChanges = $this->config->load($this->configFile)->getChanges();

        foreach ($appliedChanges as $appliedChange) {
            if ($changeIds === [] || in_array((string)$appliedChange->getNumber(), $changeIds, true)) {
                $patchesCount += $this->createPatches(
                    [(string)$appliedChange->getNumber()],
                    $appliedChange->getPatchDirectory(),
                    $appliedChange->getTests(),
                    $affectedPackages
                );
            }
        }

        return $patchesCount;
    }

    /**
     * @return array<int, string>
     */
    public function verifyPatchesForPackage(PackageInterface $package): array
    {
        $obsoleteChanges = [];
        $appliedChanges = $this->config->load($this->configFile)->getChanges();
        $patches = $this->getPatches();

        foreach ($appliedChanges as $appliedChange) {
            $packages = $this->getPackagesForChange($appliedChange->getNumber());

            if (in_array($package->getName(), $packages, true)) {
                $includedInInfo = $this->gerritRestApi->getIncludedIn((string)$appliedChange->getNumber());

                foreach ($includedInInfo->tags as $tag) {
                    if (Semver::satisfies($package->getVersion(), $tag)) {
                        if ($this->askRemoval($appliedChange->getNumber())) {
                            $obsoleteChanges[] = (string)$appliedChange->getNumber();
                            $this->patchUtils->prepareRemove([$appliedChange->getNumber()], $patches);
                        }

                        continue 2;
                    }
                }

                foreach ($includedInInfo->branches as $branch) {
                    if (Semver::satisfies($package->getVersion(), 'dev-' . $branch)) {
                        if ($this->askRemoval($appliedChange->getNumber())) {
                            $obsoleteChanges[] = (string)$appliedChange->getNumber();
                            $this->patchUtils->prepareRemove([$appliedChange->getNumber()], $patches);
                        }

                        continue 2;
                    }
                }
            }
        }

        return $obsoleteChanges;
    }

    /**
     * @throws CommandExecutionException
     */
    public function updateLock(OutputInterface $output): void
    {
        $this->checkCommandResult(
            $this->application->run(
                new ArrayInput([
                    'command' => 'update',
                    '--lock' => true,
                ]),
                $output
            ),
            0,
            'Error while updating the Composer lock file.'
        );
    }
}
