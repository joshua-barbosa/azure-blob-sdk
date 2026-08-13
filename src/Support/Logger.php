<?php

namespace AzureBlob\Support;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Resolve o canal de log do SDK.
 *
 * O canal (`azure-blob` por padrão) é registrado pelo ServiceProvider. Se ele não
 * existir — porque o pacote foi instanciado fora do boot do Laravel, ou porque
 * a configuração foi removida — cai para o logger padrão em vez de estourar.
 */
final class Logger
{
    public static function resolve(Config $config, ?Container $container = null): LoggerInterface
    {
        if (! ($config->logging['enabled'] ?? true)) {
            return new NullLogger;
        }

        $container ??= self::container();

        if ($container === null || ! $container->bound('log')) {
            return new NullLogger;
        }

        // Illuminate\Log\LogManager implementa LoggerInterface e expõe channel().
        $manager = $container->make('log');

        $channel = $config->logging['channel'] ?? null;
        $channel = is_string($channel) ? trim($channel) : '';

        if ($channel === '' || ! method_exists($manager, 'channel')) {
            return $manager instanceof LoggerInterface ? $manager : new NullLogger;
        }

        try {
            return $manager->channel($channel);
        } catch (Throwable) {
            // Canal não configurado: registrar no logger padrão é melhor do que
            // derrubar uma operação por causa de log.
            return $manager instanceof LoggerInterface ? $manager : new NullLogger;
        }
    }

    private static function container(): ?Container
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            return app();
        } catch (Throwable) {
            return null;
        }
    }
}
