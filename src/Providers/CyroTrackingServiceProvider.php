<?php

namespace CyroTracking\Providers;

use IO\Helper\ResourceContainer;
use Plenty\Modules\Webshop\Consent\Contracts\ConsentRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider;

class CyroTrackingServiceProvider extends ServiceProvider
{
    const PRIORITY = 0;

    public function register()
    {
        $this->getApplication()->register(CyroTrackingRouteServiceProvider::class);
    }

    public function boot(Dispatcher $dispatcher, ConfigRepository $config)
    {
        $this->registerConsent($config);

        if (!$this->isEnabled($config)) {
            return;
        }

        $dispatcher->listen('IO.Resources.Import', function (ResourceContainer $container) {
            $container->addScriptTemplate('CyroTracking::Tracking.Tracker');
        }, self::PRIORITY);
    }

    private function registerConsent(ConfigRepository $config)
    {
        /** @var ConsentRepositoryContract $consentRepository */
        $consentRepository = pluginApp(ConsentRepositoryContract::class);

        $consentRepository->registerConsent(
            'cyroAnalytics',
            'CyroTracking::Template.consentCyroAnalyticsLabel',
            [
                'position' => 100,
                'description' => 'CyroTracking::Template.consentCyroAnalyticsDescription',
                'provider' => 'CyroTracking::Template.consentCyroAnalyticsProvider',
                'lifespan' => 'CyroTracking::Template.consentCyroAnalyticsLifespan',
                'policyUrl' => 'CyroTracking::Template.consentCyroAnalyticsPolicyUrl',
                'group' => 'tracking',
                'cookieNames' => ['_ga', '/^_ga_.*/', '_gid', '_gat', '_gcl_au']
            ]
        );

        if (trim((string)$config->get('CyroTracking.matomo_site_url', '')) !== '' && trim((string)$config->get('CyroTracking.matomo_site_id', '')) !== '') {
            $consentRepository->registerConsent(
                'cyroMatomo',
                'CyroTracking::Template.consentCyroMatomoLabel',
                [
                    'position' => 101,
                    'description' => 'CyroTracking::Template.consentCyroMatomoDescription',
                    'provider' => 'CyroTracking::Template.consentCyroMatomoProvider',
                    'lifespan' => 'CyroTracking::Template.consentCyroMatomoLifespan',
                    'policyUrl' => 'CyroTracking::Template.consentCyroMatomoPolicyUrl',
                    'group' => 'tracking',
                    'cookieNames' => ['/^_pk_id.*/', '/^_pk_ses.*/', '/^_pk_ref.*/', '/^_pk_cvar.*/', 'mtm_consent', 'matomo_ignore']
                ]
            );
        }

        $consentRepository->registerConsent(
            'cyroMarketing',
            'CyroTracking::Template.consentCyroMarketingLabel',
            [
                'position' => 100,
                'description' => 'CyroTracking::Template.consentCyroMarketingDescription',
                'provider' => 'CyroTracking::Template.consentCyroMarketingProvider',
                'lifespan' => 'CyroTracking::Template.consentCyroMarketingLifespan',
                'policyUrl' => 'CyroTracking::Template.consentCyroMarketingPolicyUrl',
                'group' => 'marketing',
                'cookieNames' => ['_fbp', '_fbc']
            ]
        );
    }

    private function isEnabled(ConfigRepository $config)
    {
        $enabled = $config->get('CyroTracking.enabled', true);

        return $enabled === null || $enabled === '' || $enabled === true || $enabled === 'true' || $enabled === '1' || $enabled === 1;
    }
}
