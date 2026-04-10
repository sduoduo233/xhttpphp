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


error_reporting(E_ALL);

function hex_dump($data)
{
    return;
    
    static $from = '';
    static $to = '';
    
    static $width = 16; # number of bytes per line
    
    static $pad = '.'; # padding for non-visible characters
    
    if ($from==='')
    {
        for ($i=0; $i<=0xFF; $i++)
        {
        $from .= chr($i);
        $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
        }
    }
    
    $hex = str_split(bin2hex($data), $width*2);
    $chars = str_split(strtr($data, $from, $to), $width);
    
    $offset = 0;
    foreach ($hex as $i => $line)
    {
        logf("%s", sprintf('%6X',$offset). ' : ' .implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . "\n");

        $offset += $width;
    }
}