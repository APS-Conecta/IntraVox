<?php

declare(strict_types=1);

namespace APS\Common;

/**
 * The org's one refusal posture (L2-02): a submission understood and refused
 * is a 422 whose message names what to fix, and a record nobody has is a 404
 * — never a silent 200 and never a 500 a person reading the screen cannot
 * see. Territorio's trait, lifted verbatim: same methods, same defaults,
 * same docblock doctrine — now the one copy both apps' controllers mix in.
 *
 * TWO things ship under this name, at different testability tiers, one file
 * each:
 *
 *  - `Refusal`, the marker interface (this file, BARE PHP) — a package
 *    suite loads and asserts it in milliseconds, and every app's
 *    Invalid*Exception family implements it so one catch names them all.
 *    It is in its own file because every autoload tier resolves by
 *    filename: composer PSR-4 maps `APS\Common\Refusal` → src/Refusal.php,
 *    and a marker buried in Refuses.php is an order-dependent
 *    "Interface not found" 500.
 *  - `Refuses`, the trait (Refuses.php, OCP-BOUND: JSONResponse, Http,
 *    DoesNotExistException), is therefore NEVER loaded by this package's
 *    own suite — it ships for the APPS' autoloaders, where those classes
 *    exist. That is the one documented exception to this package's
 *    "depends on no OCP class" rule: the alternative was an OCP-bound copy
 *    per app, which is the five-copies drift this extraction exists to end.
 *    `RefusalTest` pins the marker; the trait is pinned by every controller
 *    test in both apps that asserts a 422 body.
 */
interface Refusal extends \Throwable {
}
