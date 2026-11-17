<?php

declare(strict_types=1);

namespace Acta\Http\EventSourcing;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\Http\Exception\HttpException;
use Psr\Http\Message\MessageInterface;

/**
 * Carries the version of an aggregate in the `ETag` and `If-Match` headers.
 *
 * A read answers with `ETag: "7"`; a write sent with `If-Match: "7"` only succeeds when
 * the aggregate is still at version 7, which protects a client from overwriting changes
 * it has not seen.
 *
 * Requires `acta/event-sourcing`.
 */
final class IfMatch
{
    /**
     * Returns the `ETag` value of an aggregate version.
     */
    public static function etag(int $version): string
    {
        return sprintf('"%d"', $version);
    }

    /**
     * Reads the version a request expects: any when it has no `If-Match` header, an
     * existing aggregate for `*`, the exact version otherwise.
     *
     * @throws HttpException 400 when the header is not a version produced by {@see self::etag()}
     */
    public static function expectedVersion(MessageInterface $request): ExpectedVersion
    {
        $header = trim($request->getHeaderLine('If-Match'));

        return match (true) {
            $header === '' => ExpectedVersion::any(),
            $header === '*' => ExpectedVersion::streamExists(),
            preg_match('/^"(\d+)"$/', $header, $matches) === 1 => ExpectedVersion::exactly((int) $matches[1]),
            default => throw HttpException::badRequest('The If-Match header must be "*" or a quoted version such as "7".'),
        };
    }

    /**
     * Refuses a request whose `If-Match` header does not designate the current version.
     *
     * @param int $currentVersion version of the aggregate as it was just loaded, 0 when it does not exist
     *
     * @throws HttpException 412 when the client acts on a version that is not the current one, 400 for an unreadable header
     */
    public static function assert(MessageInterface $request, int $currentVersion): void
    {
        $expected = self::expectedVersion($request);

        if (!$expected->isSatisfiedBy($currentVersion)) {
            throw HttpException::preconditionFailed($currentVersion === 0 ? 'The resource does not exist.' : sprintf(
                'The resource is at version %d, not at %s. Read it again, then retry.',
                $currentVersion,
                $expected->describe(),
            ));
        }
    }
}
