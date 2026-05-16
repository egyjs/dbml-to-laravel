<?php

declare(strict_types=1);

namespace Egyjs\DbmlToLaravel\Parsing\Dbml;

class ColumnPair
{
    public Column $old;
    public Column $new;

    public function __construct(Column $old, Column $new)
    {
        $this->old = $old;
        $this->new = $new;
    }
}