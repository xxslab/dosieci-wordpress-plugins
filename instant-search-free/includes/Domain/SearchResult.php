<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchResult {

	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $url,
		public readonly ?string $sku = null,
		public readonly ?string $price = null,
		public readonly ?string $imageUrl = null,
		public readonly int $score = 0
	) {
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'id'    => $this->id,
			'title' => $this->title,
			'url'   => $this->url,
			'sku'   => $this->sku,
			'price' => $this->price,
			'image' => $this->imageUrl,
		);
	}
}
