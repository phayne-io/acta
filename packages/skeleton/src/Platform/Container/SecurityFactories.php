<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\Oidc\Client\FileTokenStorage;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\PublicClient;
use Acta\Oidc\Client\TokenStorage;
use Acta\Oidc\Discovery\Discovery;
use Acta\Oidc\Discovery\MetadataSource;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jwks\JwksProvider;
use Acta\Oidc\Jwks\KeySource;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\JwtVerifier;
use App\Platform\Http\BearerAuthenticator;
use App\Platform\Settings;
use GuzzleHttp\Client;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds the OAuth 2.0 and OpenID Connect side of the application.
 *
 * As a resource server, the API verifies the Bearer JWT of each request against the keys
 * the identity provider publishes (JWKS). As a client, the console logs a person in with
 * the device flow. Both discover the provider from `acta.oidc.issuer`.
 */
final class SecurityFactories
{
    /**
     * The PSR-18 client used for every outgoing call: the identity provider and the
     * subscribers of the webhooks.
     */
    public static function httpClient(): ClientInterface
    {
        return new Client(['timeout' => 5, 'connect_timeout' => 2, 'http_errors' => false, 'allow_redirects' => false]);
    }

    /**
     * Where the metadata and the keys of the identity provider are kept between requests:
     * on the Valkey or Redis server of `acta.cache_dsn`, which every instance of the
     * application then shares, or in files of this machine when none is configured.
     *
     * The server is only contacted when the cache is first read or written.
     */
    public static function cache(ContainerInterface $container): CacheInterface
    {
        $settings = Settings::of($container);
        $dsn = $settings->string('acta.cache_dsn');

        return new Psr16Cache(
            $dsn !== ''
                ? new RedisAdapter(RedisAdapter::createConnection($dsn, ['lazy' => true]), 'acta')
                : new FilesystemAdapter('acta', 0, $settings->path('acta.cache_dir', 'data/cache')),
        );
    }

    public static function transport(ContainerInterface $container): Transport
    {
        return new Transport(
            Resolve::from($container, ClientInterface::class),
            Resolve::from($container, RequestFactoryInterface::class),
            Resolve::from($container, StreamFactoryInterface::class),
        );
    }

    public static function provider(ContainerInterface $container): MetadataSource
    {
        $settings = Settings::of($container);
        $issuer = $settings->string('acta.oidc.issuer');

        return new Discovery(
            $issuer !== '' ? $issuer : throw new LogicException('No identity provider is configured: set OIDC_ISSUER.'),
            Resolve::from($container, Transport::class),
            Resolve::from($container, CacheInterface::class),
            allowInsecure: $settings->bool('acta.oidc.allow_insecure'),
        );
    }

    public static function keys(ContainerInterface $container): KeySource
    {
        return new JwksProvider(
            Resolve::from($container, MetadataSource::class),
            Resolve::from($container, Transport::class),
            Resolve::from($container, CacheInterface::class),
            logger: Resolve::from($container, LoggerInterface::class),
        );
    }

    public static function jwtVerifier(ContainerInterface $container): JwtVerifier
    {
        return new JwtVerifier(
            Resolve::from($container, MetadataSource::class),
            Resolve::from($container, KeySource::class),
            clock: Resolve::from($container, ClockInterface::class),
        );
    }

    public static function accessTokenVerifier(ContainerInterface $container): AccessTokenVerifier
    {
        $audience = Settings::of($container)->string('acta.oidc.audience');

        return new AccessTokenVerifier(
            Resolve::from($container, JwtVerifier::class),
            $audience !== '' ? $audience : throw new LogicException('No audience is configured: set OIDC_AUDIENCE to the identifier of this API.'),
        );
    }

    public static function authenticator(ContainerInterface $container): BearerAuthenticator
    {
        return new BearerAuthenticator(Resolve::from($container, AccessTokenVerifier::class));
    }

    /**
     * The client the console logs in with: a public client, since a command-line tool
     * cannot keep a secret.
     */
    public static function oauthClient(ContainerInterface $container): OAuthClient
    {
        $clientId = Settings::of($container)->string('acta.oidc.client_id');

        return new OAuthClient(
            $clientId !== '' ? $clientId : throw new LogicException('No OAuth client is configured: set OIDC_CLIENT_ID.'),
            new PublicClient(),
            Resolve::from($container, MetadataSource::class),
            Resolve::from($container, Transport::class),
            Resolve::from($container, JwtVerifier::class),
            Resolve::from($container, ClockInterface::class),
        );
    }

    public static function tokenStorage(ContainerInterface $container): TokenStorage
    {
        return new FileTokenStorage(Settings::of($container)->path('acta.oidc.token_file', 'data/token.json'));
    }
}
