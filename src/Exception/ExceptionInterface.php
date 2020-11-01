<?php

namespace App\Exception;

interface ExceptionInterface
{
    /**
     * Gets the Exception message.
     *
     * @return string
     */
    public function getMessage();

    /**
     * Gets the Exception code.
     *
     * @return int
     */
    public function getCode();
}