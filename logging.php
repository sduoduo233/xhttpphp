<?php

/*
Copyright (C) 2026 duoduo

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

// LOGGING_MODE: 'stderr' | 'file' | 'discard' (default)
// When 'file', logs are appended to xhttp.log in the same directory as this file.

define("LOGGING_MODE", "stderr");

function logf(string $format, mixed ...$args): void {
    $mode = defined('LOGGING_MODE') ? LOGGING_MODE : 'discard';
    if ($mode === 'discard') return;

    $message = sprintf($format, ...$args);

    if ($mode === 'stderr') {
        fwrite(STDERR, $message);
    } elseif ($mode === 'file') {
        file_put_contents(__DIR__ . '/xhttp.log', $message, FILE_APPEND | LOCK_EX);
    }
}
