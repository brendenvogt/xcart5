<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Query\Resolver;

use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;
use Silex\Application;
use XCart\Bus\Client\MarketplaceClient;
use XCart\Bus\Core\Annotations\Resolver;
use XCart\Bus\Query\Data\CoreConfigDataSource;
use XCart\Bus\Query\Data\InstalledModulesDataSource;
use XCart\Bus\Query\Data\MarketplaceShopAdapter;
use XCart\Bus\System\DBInfo;
use XCart\SilexAnnotations\Annotations\Service;

/**
 * @Service\Service()
 */
class SystemDataResolver
{
    /**
     * @var InstalledModulesDataSource
     */
    private $installedModulesDataSource;

    /**
     * @var CoreConfigDataSource
     */
    private $coreConfigDataSource;

    /**
     * @var MarketplaceClient
     */
    private $marketplaceClient;

    /**
     * @var MarketplaceShopAdapter
     */
    private $marketplaceShopAdapter;

    /**
     * @var DBInfo
     */
    private $dbInfo;

    /**
     * @var bool
     */
    private $demoMode;

    /**
     * @var bool
     */
    private $pharIsInstalled;

    /**
     * @param Application                $app
     * @param InstalledModulesDataSource $installedModulesDataSource
     * @param CoreConfigDataSource       $coreConfigDataSource
     * @param MarketplaceClient          $marketplaceClient
     * @param DBInfo                     $dbInfo
     *
     * @return SystemDataResolver
     *
     * @Service\Constructor
     * @codeCoverageIgnore
     */
    public static function serviceConstructor(
        Application $app,
        InstalledModulesDataSource $installedModulesDataSource,
        CoreConfigDataSource $coreConfigDataSource,
        MarketplaceClient $marketplaceClient,
        MarketplaceShopAdapter $marketplaceShopAdapter,
        DBInfo $dbInfo
    ) {
        return new self(
            $installedModulesDataSource,
            $coreConfigDataSource,
            $marketplaceClient,
            $marketplaceShopAdapter,
            $dbInfo,
            $app['config']['phar_is_installed'],
            $app['config']['email'],
            $app['xc_config']['demo']['demo_mode'] ?? false
        );
    }

    /**
     * @param InstalledModulesDataSource $installedModulesDataSource
     * @param CoreConfigDataSource       $coreConfigDataSource
     * @param MarketplaceClient          $marketplaceClient
     * @param DBInfo                     $dbInfo
     * @param boolean                    $pharIsInstalled
     * @param string                     $email
     * @param boolean                    $demoMode
     */
    public function __construct(
        InstalledModulesDataSource $installedModulesDataSource,
        CoreConfigDataSource $coreConfigDataSource,
        MarketplaceClient $marketplaceClient,
        MarketplaceShopAdapter $marketplaceShopAdapter,
        DBInfo $dbInfo,
        $pharIsInstalled,
        $email,
        $demoMode
    ) {
        $this->installedModulesDataSource = $installedModulesDataSource;
        $this->coreConfigDataSource       = $coreConfigDataSource;
        $this->marketplaceClient          = $marketplaceClient;
        $this->marketplaceShopAdapter     = $marketplaceShopAdapter;
        $this->dbInfo                     = $dbInfo;
        $this->pharIsInstalled            = $pharIsInstalled;
        $this->email                      = $email;
        $this->demoMode                   = $demoMode;
    }

    /**
     * @return string
     */
    public function getMysqlVersion(): string
    {
        $dbInfo = $this->coreConfigDataSource->dbInfo;

        $expiration = $dbInfo['expiration'] ?? 0;
        if ($expiration < time()) {
            $dbInfo['version']    = $this->dbInfo->getDBVersion();
            $dbInfo['expiration'] = time() + (60 * 60 * 24);
        }

        return $dbInfo['version'];
    }

    /**
     * @param             $value
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     *
     * @return array
     *
     * @Resolver()
     */
    public function resolveInstallationData($value, $args, $context, ResolveInfo $info): array
    {
        $core             = $this->installedModulesDataSource->find('CDev-Core');
        $installationDate = $core ? $core['installedDate'] : 0;

        $backupMaster            = $this->installedModulesDataSource->find('QSL-Backup');
        $backupMasterIsEnabled   = $backupMaster ? $backupMaster['enabled'] : false;
        $backupMasterIsInstalled = $backupMaster ? $backupMaster['installed'] : false;

        return [
            'installationDate'        => $installationDate,
            'trialExpired'            => ($installationDate + 2592000 - time()) <= 0, /* 86400 * 30 */
            'backupMasterIsEnabled'   => $backupMasterIsEnabled,
            'backupMasterIsInstalled' => $backupMasterIsInstalled,
        ];
    }

    /**
     * @param             $value
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     *
     * @return Deferred
     *
     * @Resolver()
     */
    public function resolveSystemData($value, $args, $context, ResolveInfo $info): Deferred
    {
        return new Deferred(function () {
            $marketplaceLockExpiration = $this->coreConfigDataSource->find('marketplaceLockExpiration');

            return [
                'cacheDate'       => $this->coreConfigDataSource->cacheDate ?: 0,
                'dataDate'        => $this->coreConfigDataSource->dataDate ?: 0,
                'authLock'        => $this->coreConfigDataSource->authLock ?: 0,
                'wave'            => $this->coreConfigDataSource->wave,
                'marketplaceLock' => $marketplaceLockExpiration && time() < (int) $marketplaceLockExpiration,
                'purchaseUrl'     => $this->marketplaceShopAdapter->get()->getPurchaseURL(),
                'pharIsInstalled' => $this->pharIsInstalled,
                'email'           => $this->email,
                'demoMode'        => $this->demoMode,
            ];
        });
    }

    /**
     * @param             $value
     * @param             $args
     * @param             $context
     * @param ResolveInfo $info
     *
     * @return Deferred
     *
     * @Resolver()
     */
    public function resolveMarketplaceState($value, $args, $context, ResolveInfo $info): Deferred
    {
        if (!empty($args['force'])) {
            $this->marketplaceClient->getTest();
        }

        return new Deferred(function () {
            $marketplaceLockExpiration = $this->coreConfigDataSource->find('marketplaceLockExpiration');

            return [
                'marketplaceLock' => $marketplaceLockExpiration && time() < (int) $marketplaceLockExpiration,
            ];
        });
    }
}
