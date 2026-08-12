<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

interface GameProvider
{
    public function id(): string;
    public function name(): string;
    public function slug(): string;
    public function skuPrefix(): string;

    /** @return array<string,string> Logical field => existing post meta key. */
    public function metaKeys(): array;

    /** @return array<string,bool> Capability flags used by Core and future shared UI. */
    public function capabilities(): array;

    /** @return array<string,mixed> Non-secret display/integration metadata. */
    public function config(): array;
}
