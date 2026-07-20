<?php

namespace ControleOnline\Entity {
    class People
    {
        public function __construct(private ?int $id = null) {}

        public function getId(): ?int
        {
            return $this->id;
        }
    }

    class Spool
    {
        public function __construct(private ?int $id = null) {}

        public function getId(): ?int
        {
            return $this->id;
        }
    }

    class Order
    {
        private ?int $id = null;
        private ?People $provider = null;
        private array|string|null $otherInformations = null;
        private ?string $app = null;

        public function __construct(
            ?int $id = null,
            ?People $provider = null,
            array|string|null $otherInformations = null,
            ?string $app = null,
        ) {
            $this->id = $id;
            $this->provider = $provider;

            if ($app === null && is_string($otherInformations)) {
                $this->app = $otherInformations;
                $this->otherInformations = null;

                return;
            }

            $this->otherInformations = $otherInformations;
            $this->app = $app;
        }

        public function getId(): ?int
        {
            return $this->id;
        }

        public function getProvider(): ?People
        {
            return $this->provider;
        }

        public function getOtherInformations($decode = false)
        {
            if (!$decode) {
                return $this->otherInformations;
            }

            if (is_array($this->otherInformations)) {
                return (object) json_decode(json_encode($this->otherInformations));
            }

            if (is_string($this->otherInformations) && trim($this->otherInformations) !== '') {
                return json_decode($this->otherInformations) ?: new \stdClass();
            }

            return new \stdClass();
        }

        public function setOtherInformations($otherInformations): self
        {
            $normalized = json_decode(json_encode($otherInformations), true);
            $this->otherInformations = is_array($normalized) ? $normalized : [];

            return $this;
        }

        public function getStoredOtherInformations(): array
        {
            if (is_array($this->otherInformations)) {
                return $this->otherInformations;
            }

            if (is_string($this->otherInformations) && trim($this->otherInformations) !== '') {
                $decoded = json_decode($this->otherInformations, true);
                return is_array($decoded) ? $decoded : [];
            }

            return [];
        }

        public function getApp(): ?string
        {
            return $this->app;
        }
    }
}
