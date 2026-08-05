<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour les tokens de validation.
 *
 * READ methods: TokenReadQueriesTrait, TokenReadSubmissionTrait, TokenReadCheckTrait
 * WRITE methods: TokenWriteQueriesTrait
 */
final class TokenRepository extends BaseRepository
{
    use TokenReadQueriesTrait;
    use TokenReadSubmissionTrait;
    use TokenReadCheckTrait;
    use TokenWriteQueriesTrait;
}
