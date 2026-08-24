<?php

namespace NimblePHP\Payments\Exceptions;

use RuntimeException;

/**
 * PAY-M01: applyProviderUpdate() tried to write a provider_session_id that
 * the unique index from migration 1787522400 (PAY-H02) already holds on a
 * different row - two registerTransaction() calls (retry, double submit,
 * race) landed on the same provider session but ended up as two separate
 * local rows.
 *
 * This turns what would otherwise be a raw, driver-specific PDOException
 * into something a caller can catch and act on (e.g. look up and return the
 * already-registered transaction instead of failing outright). It does not
 * by itself make registerTransaction() idempotent - true idempotency needs
 * either a caller-supplied idempotency key or a lookup-before-register step,
 * which is a product decision left to the host application; this exception
 * only makes the failure mode legible.
 */
class DuplicateProviderSessionException extends RuntimeException
{
}
