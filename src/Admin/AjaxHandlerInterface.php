<?php
/**
 * Contract for all AJAX handlers routed through the AjaxRouter.
 *
 * Each handler receives the already-verified request payload (nonce + capability
 * checks live on the router) and returns a structured response array that the
 * router serialises via wp_send_json_success / wp_send_json_error.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

interface AjaxHandlerInterface
{
    /**
     * Handle a single AJAX request.
     *
     * @param array<string,mixed> $request Sanitised copy of $_POST / $_GET.
     * @return array{success:bool,data?:mixed,message?:string} Structured result.
     */
    public function handle(array $request): array;
}
