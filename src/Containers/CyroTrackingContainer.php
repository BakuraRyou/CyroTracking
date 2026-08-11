<?php

namespace CyroTracking\Containers;

use Plenty\Plugin\Templates\Twig;

class CyroTrackingContainer
{
    public function call(Twig $twig): string
    {
        return $twig->render('CyroTracking::Tracking.Tracker');
    }
}
