<?php
/* =========================================================================
 * Client reference ID (CL-0001, CL-0002 ...), owner's request 2026-11-03.
 *
 * clients.client_id stays the primary key (every foreign key and URL uses
 * it); clients.client_code is the reference people read, type and search,
 * so two clients with the same name can be told apart. Numbers are given
 * once, in order, and never reused (a deleted client keeps its code). The
 * next number comes from the client_code_seq row in system_settings, see
 * client_code_next() in includes/data/clients.php.
 * ========================================================================= */

const CLIENT_CODE_PREFIX = 'CL-';

/** CL-0007 for 7. Past 9999 it simply grows (CL-10000), it is never cut. */
function client_code_format(int $n): string
{
    return CLIENT_CODE_PREFIX . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

