<?php

namespace DoctolibChecker\Dto;

use Symfony\Component\Notifier\Transport;
use Symfony\Component\Notifier\Transport\TransportInterface;

final class Config
{
    /** @var TransportInterface[] */
    public readonly array $transports;

    /**
     * @param Doctor[] $doctors
     * @param string[] $notificationChannels
     * @return void
     */
    public function __construct(
        public readonly array $doctors,
        array $notificationChannels = [],
    ) {
        $transports = [];

        foreach ($notificationChannels as $notificationChannel) {
            $transports[] = Transport::fromDsn($notificationChannel);
        }

        $this->transports = $transports;
    }
}
