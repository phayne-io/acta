<?php

declare(strict_types=1);

namespace Acta\Oidc\Token;

/**
 * Why a token was refused.
 */
enum TokenError: string
{
    case Malformed = 'malformed';
    case UnsupportedAlgorithm = 'unsupported_algorithm';
    case WrongType = 'wrong_type';
    case UnknownKey = 'unknown_key';
    case BadSignature = 'bad_signature';
    case WrongIssuer = 'wrong_issuer';
    case WrongAudience = 'wrong_audience';
    case Expired = 'expired';
    case NotYetValid = 'not_yet_valid';
    case MissingClaim = 'missing_claim';
    case WrongNonce = 'wrong_nonce';
    case WrongParty = 'wrong_party';
    case WrongHash = 'wrong_hash';
    case WrongSubject = 'wrong_subject';
}
