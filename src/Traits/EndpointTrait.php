<?php

/*
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Traits;

trait EndpointTrait {

    /*
     * Get array of endpoints from the queue options
     */

    private function endpoints($queues, $key) {
        $endpoints = array();

        foreach ($queues as $queue) {
            $options = $queue->getOptions();
            if (array_key_exists($key, $options)) {
                $endpoints[] = $options[$key];
            }
        }

        return array_unique($endpoints);
    }
}
