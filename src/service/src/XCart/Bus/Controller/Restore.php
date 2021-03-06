<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Controller;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use XCart\Bus\Domain\Module;
use XCart\Bus\Query\Data\ChangelogDataSource;
use XCart\Bus\Query\Data\CoreConfigDataSource;
use XCart\Bus\Query\Data\InstalledModulesDataSource;
use XCart\Bus\Query\Data\KnownHashesCacheDataSource;
use XCart\Bus\Query\Data\LicenseDataSource;
use XCart\Bus\Query\Data\MarketplaceModulesDataSource;
use XCart\Bus\Query\Data\ScenarioDataSource;
use XCart\Bus\Query\Data\ScriptStateDataSource;
use XCart\Bus\Query\Data\SetDataSource;
use XCart\Bus\Query\Data\UploadedModulesDataSource;
use XCart\Bus\Query\Resolver\RebuildResolver;
use XCart\Bus\Rebuild\Executor\RebuildLockManager;
use XCart\Bus\Rebuild\Scenario\ChangeUnitProcessor;
use XCart\SilexAnnotations\Annotations\Router;
use XCart\SilexAnnotations\Annotations\Service;

/**
 * @Service\Service()
 * @Router\Controller()
 */
class Restore
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var MarketplaceModulesDataSource
     */
    private $marketplaceModulesDataSource;

    /**
     * @var UploadedModulesDataSource
     */
    private $uploadedModulesDataSource;

    /**
     * @var InstalledModulesDataSource
     */
    private $installedModulesDataSource;

    /**
     * @var ScenarioDataSource
     */
    private $scenarioDataSource;

    /**
     * @var ScriptStateDataSource
     */
    private $scriptStateDataSource;

    /**
     * @var CoreConfigDataSource
     */
    private $coreConfigDataSource;

    /**
     * @var SetDataSource
     */
    private $setDataSource;

    /**
     * @var RebuildLockManager
     */
    private $rebuildLockManager;

    /**
     * @var LicenseDataSource
     */
    private $licenseDataSource;

    /**
     * @var ChangelogDataSource
     */
    private $changelogDataSource;

    /**
     * @var KnownHashesCacheDataSource
     */
    private $knownHashesCacheDataSource;

    /**
     * @var ChangeUnitProcessor
     */
    private $changeUnitProcessor;

    /**
     * @var RebuildResolver
     */
    private $rebuildResolver;

    /**
     * @param Application                  $app
     * @param MarketplaceModulesDataSource $marketplaceModulesDataSource
     * @param UploadedModulesDataSource    $uploadedModulesDataSource
     * @param InstalledModulesDataSource   $installedModulesDataSource
     * @param ScenarioDataSource           $scenarioDataSource
     * @param ScriptStateDataSource        $scriptStateDataSource
     * @param CoreConfigDataSource         $coreConfigDataSource
     * @param SetDataSource                $setDataSource
     * @param RebuildLockManager           $rebuildLockManager
     * @param LicenseDataSource            $licenseDataSource
     * @param ChangelogDataSource          $changelogDataSource
     * @param KnownHashesCacheDataSource   $knownHashesCacheDataSource
     * @param ChangeUnitProcessor          $changeUnitProcessor
     * @param RebuildResolver              $rebuildResolver
     */
    public function __construct(
        Application $app,
        MarketplaceModulesDataSource $marketplaceModulesDataSource,
        UploadedModulesDataSource $uploadedModulesDataSource,
        InstalledModulesDataSource $installedModulesDataSource,
        ScenarioDataSource $scenarioDataSource,
        ScriptStateDataSource $scriptStateDataSource,
        CoreConfigDataSource $coreConfigDataSource,
        SetDataSource $setDataSource,
        RebuildLockManager $rebuildLockManager,
        LicenseDataSource $licenseDataSource,
        ChangelogDataSource $changelogDataSource,
        KnownHashesCacheDataSource $knownHashesCacheDataSource,
        ChangeUnitProcessor $changeUnitProcessor,
        RebuildResolver $rebuildResolver
    ) {
        $this->app                          = $app;
        $this->marketplaceModulesDataSource = $marketplaceModulesDataSource;
        $this->uploadedModulesDataSource    = $uploadedModulesDataSource;
        $this->installedModulesDataSource   = $installedModulesDataSource;
        $this->scenarioDataSource           = $scenarioDataSource;
        $this->scriptStateDataSource        = $scriptStateDataSource;
        $this->rebuildLockManager           = $rebuildLockManager;
        $this->coreConfigDataSource         = $coreConfigDataSource;
        $this->setDataSource                = $setDataSource;
        $this->licenseDataSource            = $licenseDataSource;
        $this->changelogDataSource          = $changelogDataSource;
        $this->knownHashesCacheDataSource   = $knownHashesCacheDataSource;
        $this->changeUnitProcessor          = $changeUnitProcessor;
        $this->rebuildResolver              = $rebuildResolver;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/restore/start"),
     * )
     */
    public function startAction(Request $request): Response
    {
        $this->marketplaceModulesDataSource->clear();

        $this->scenarioDataSource->clear();
        $this->scriptStateDataSource->clear();

        $this->licenseDataSource->clear();

        $licenseJSON = $request->get('license') ?: null;
        $license     = $licenseJSON ? json_decode($licenseJSON, true) : [];
        if ($license) {
            $this->licenseDataSource->saveOne($license);
        }

        $this->setDataSource->clear();
        $this->coreConfigDataSource->clear();
        $this->changelogDataSource->clear();
        $this->knownHashesCacheDataSource->clear();

        $this->rebuildLockManager->clearAnySetRebuildFlags();

        $coreVersion = $request->get('version') ?: null;
        $service     = Yaml::parseFile($this->app['config']['root_dir'] . 'service/src/service.yaml');

        $this->installedModulesDataSource->clear();
        $this->installedModulesDataSource->fillWithCoreModules($coreVersion, $service['Version']);

        $this->coreConfigDataSource->version = $coreVersion;

        $this->uploadedModulesDataSource->clear();
        $this->uploadedModulesDataSource->fillWithAllPossibleModules();

        $enabledModulesJSON = $request->get('modules') ?: null;
        $enabledModules     = $enabledModulesJSON ? json_decode($enabledModulesJSON, true) : [];

        $changeUnits = [];
        foreach ($this->uploadedModulesDataSource->getAll() as $info) {
            /** @var Module $module */
            $module        = $info[0];
            $changeUnits[] = [
                'id'      => $module->id,
                'install' => true,
                'version' => $module->version,
                'enable'  => $this->isModuleEnabled($enabledModules, $module->author, $module->name),
            ];
        }

        $scenario = $this->changeUnitProcessor->process($this->scenarioDataSource->startEmptyScenario(), $changeUnits);

        //$scenario['id']   = uniqid('scenario', true);
        //$scenario['type'] = 'common';
        //$scenario['date'] = time();

        $returnUrl = $request->get('returnUrl');
        if ($returnUrl) {
            $scenario['returnUrl'] = $returnUrl;
        }

        $this->scenarioDataSource->saveOne($scenario);

        $state = $this->rebuildResolver->startRebuild(null, ['id' => $scenario['id'], 'type' => 'install'], null, new ResolveInfo([]));

        return new Response($state->id);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/restore/rebuild"),
     * )
     */
    public function rebuildAction(Request $request): Response
    {
        $id = $request->get('id');

        $state = $this->rebuildResolver->executeRebuild(null, ['id' => $id], null, new ResolveInfo([]));

        if ($state->errorTitle) {
            throw new Exception($state->errorTitle);
        }

        if (isset($state->currentStepInfo[0])) {
            $service     = Yaml::parseFile($this->app['config']['root_dir'] . 'service/src/service.yaml');
            $labels      = $service['LanguageLabel'];
            $stepMessage = $state->currentStepInfo[0]['message'];
            $params      = $state->currentStepInfo[0]['params'];

            $message = '';
            foreach ($labels as $label) {
                if ($label['name'] === $stepMessage) {
                    $message = $label['label'];
                }
            }

            if (!empty($params) && $params = @json_decode($params)) {
                foreach (array_values($params) as $index => $value) {
                    $message = str_replace('{' . $index . '}', $value, $message);
                }
            }

            return new Response($message);
        }

        return new Response('done');
    }

    /**
     * @return Response
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/restore/clear-cache")
     * )
     */
    public function clearCacheAction(): Response
    {
        $this->marketplaceModulesDataSource->clear();
        $this->setDataSource->clear();

        return new Response('done');
    }

    /**
     * @param array  $enabledModules
     * @param string $author
     * @param string $name
     *
     * @return bool
     */
    private function isModuleEnabled(array $enabledModules, string $author, string $name): bool
    {
        return in_array(Module::buildModuleId($author, $name), $enabledModules, true);
    }
}
