<?php

namespace ControleOnline\Entity;

final class People
{
    private ?int $id = null;

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
