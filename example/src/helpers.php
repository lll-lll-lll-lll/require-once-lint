<?php

function app_helper_shout(string $text): string
{
    return strtoupper($text) . '!';
}
