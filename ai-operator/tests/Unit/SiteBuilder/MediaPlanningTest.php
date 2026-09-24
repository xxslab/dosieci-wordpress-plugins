<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintPlanner;
use DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\BlockComposer;
use DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\PageContentFactory;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResourceResolverInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaCandidate;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaLibraryInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaMatcher;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\ResourceResolution;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * A wrong picture is worse than no picture: "unfinished" is a state the
 * user understands, "the builder put a scan of an invoice on my Kontakt
 * page" is one they have to undo. These tests pin the conservative side.
 */
final class MediaPlanningTest extends TestCase {

	private function image( int $id, string $title, string $filename, string $mime = 'image/jpeg' ): MediaCandidate {
		return new MediaCandidate( $id, $title, $filename, '', $mime, 1200, 800, 'https://example.test/' . $filename );
	}

	/**
	 * @param MediaCandidate[]   $images
	 * @param array<int, int>    $thumbnails page id => attachment id already set
	 */
	private function library( array $images, array $thumbnails = array() ): MediaLibraryInterface {
		return new class( $images, $thumbnails ) implements MediaLibraryInterface {
			public function __construct( private array $images, private array $thumbnails ) {
			}

			public function featuredImageId( int $pageId ): int {
				return (int) ( $this->thumbnails[ $pageId ] ?? 0 );
			}

			public function search( string $query, int $limit = 20 ): array {
				return $this->images;
			}

			public function all( int $limit = 50 ): array {
				return $this->images;
			}

			public function find( int $attachmentId ): ?MediaCandidate {
				foreach ( $this->images as $image ) {
					if ( $image->attachmentId === $attachmentId ) {
						return $image;
					}
				}

				return null;
			}
		};
	}

	private function resolver( array $decisions ): ManagedResourceResolverInterface {
		return new class( $decisions ) implements ManagedResourceResolverInterface {
			public function __construct( private array $decisions ) {
			}

			public function resolve( ManagedResource $resource, string $projectId, string $desiredContent ): ResourceResolution {
				return $this->decisions[ $resource->key() ] ?? ResourceResolution::create( $resource->key() );
			}

			public function markManaged( ManagedResource $resource, string $projectId, int $objectId, string $storedContent, ?string $authoredContent = null ): void {
			}
		};
	}

	private function plan( ?MediaLibraryInterface $media, ?ManagedResourceResolverInterface $resolver = null ) {
		return ( new BlueprintPlanner(
			new PageContentFactory( new BlockComposer() ),
			$resolver,
			$media
		) )->plan(
			SiteBuilderFixtures::blueprint(),
			'plan-m',
			'conv',
			SiteBuilderFixtures::OWNER_ID,
			SiteBuilderFixtures::NOW,
			array(),
			'proj-1'
		);
	}

	/** @return PlanAction[] */
	private function imageSteps( $plan ): array {
		return array_values(
			array_filter(
				$plan->actions,
				static fn( PlanAction $a ): bool => 'set_featured_image' === $a->toolName
			)
		);
	}

	// --- matching ---------------------------------------------------------

	public function test_an_image_whose_name_shares_a_real_word_with_the_page_matches(): void {
		$matcher = new MediaMatcher();

		$match = $matcher->bestFor(
			'Oferta',
			array( $this->image( 9, '', 'oferta-warsztat.jpg' ) )
		);

		$this->assertNotNull( $match );
		$this->assertSame( 9, $match->attachmentId );
	}

	public function test_an_unrelated_image_is_not_matched(): void {
		// The whole point: having images in the library is not a reason to
		// put one on every page.
		$matcher = new MediaMatcher();

		$this->assertNull(
			$matcher->bestFor( 'Kontakt', array( $this->image( 9, '', 'faktura-2019-skan.jpg' ) ) )
		);
	}

	public function test_generic_words_alone_never_produce_a_match(): void {
		$matcher = new MediaMatcher();

		// "image" and "photo" appear in half the filenames on any install
		// and prove nothing about what the file depicts.
		$this->assertNull(
			$matcher->bestFor( 'Nasze zdjecie', array( $this->image( 9, 'Zdjecie', 'photo-image-1.jpg' ) ) )
		);
	}

	public function test_polish_diacritics_still_match_their_ascii_filename(): void {
		// A file uploaded as "czesci.jpg" must match the page "Części",
		// because WordPress sanitises accents out of filenames on upload.
		$matcher = new MediaMatcher();

		$match = $matcher->bestFor( 'Części zamienne', array( $this->image( 4, '', 'czesci-hurt.jpg' ) ) );

		$this->assertNotNull( $match );
		$this->assertSame( 4, $match->attachmentId );
	}

	public function test_an_svg_is_never_offered_even_if_the_name_matches_perfectly(): void {
		// SVG is script-capable. A name match is not a reason to promote one
		// into a generated page.
		$matcher = new MediaMatcher();

		$this->assertNull(
			$matcher->bestFor( 'Oferta', array( $this->image( 9, 'Oferta', 'oferta.svg', 'image/svg+xml' ) ) )
		);
	}

	public function test_the_best_match_wins_and_ties_are_deterministic(): void {
		// The same site must produce the same plan hash on every run, so a
		// tie cannot be resolved by iteration luck.
		$matcher = new MediaMatcher();

		$images = array(
			$this->image( 1, '', 'oferta.jpg' ),
			$this->image( 2, '', 'oferta-hydraulika-cennik.jpg' ),
			$this->image( 3, '', 'oferta.jpg' ),
		);

		$this->assertSame( 2, $matcher->bestFor( 'Oferta hydraulika', $images )?->attachmentId );
		$this->assertSame( 1, $matcher->bestFor( 'Oferta', $images )?->attachmentId );
	}

	// --- planning ---------------------------------------------------------

	public function test_without_a_library_no_illustration_step_is_planned(): void {
		$this->assertSame( array(), $this->imageSteps( $this->plan( null ) ) );
	}

	public function test_an_empty_library_plans_no_illustration_step(): void {
		$this->assertSame( array(), $this->imageSteps( $this->plan( $this->library( array() ) ) ) );
	}

	public function test_a_matching_image_becomes_a_step_that_names_the_file(): void {
		$plan = $this->plan( $this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ) );

		$steps = $this->imageSteps( $plan );
		$this->assertCount( 1, $steps );
		$this->assertSame( 12, $steps[0]->arguments['attachment_id'] );

		// The human approving must be able to see WHICH image, otherwise the
		// approval is not informed consent.
		$this->assertStringContainsString( 'oferta-serwis.jpg', $steps[0]->description );
		$this->assertStringContainsString( 'Oferta', $steps[0]->description );
	}

	public function test_the_step_waits_for_the_page_it_illustrates(): void {
		$plan  = $this->plan( $this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ) );
		$steps = $this->imageSteps( $plan );

		$pageStep = null;
		foreach ( $plan->actions as $action ) {
			if ( 'create_post' === $action->toolName && 'page:oferta' === $action->managedResourceKey ) {
				$pageStep = $action;
			}
		}

		$this->assertNotNull( $pageStep );
		$this->assertSame( array( $pageStep->actionId ), $steps[0]->dependsOn );
		// The page id does not exist at plan time, so it must be resolved.
		$this->assertSame( 0, $steps[0]->arguments['page_id'] );
		$this->assertSame( $pageStep->actionId, $steps[0]->resolvers['page_id']['from_action'] );
	}

	public function test_a_reused_page_gets_its_real_id_with_no_resolver(): void {
		$plan = $this->plan(
			$this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ),
			$this->resolver( array( 'page:oferta' => ResourceResolution::reuse( 'page:oferta', 61, 'identical', true ) ) )
		);

		$steps = $this->imageSteps( $plan );
		$this->assertCount( 1, $steps );
		$this->assertSame( 61, $steps[0]->arguments['page_id'] );
		$this->assertSame( array(), $steps[0]->resolvers );
		$this->assertSame( array(), $steps[0]->dependsOn );
	}

	public function test_a_page_that_already_has_a_picture_is_left_alone(): void {
		// The user may have chosen that image deliberately. A swapped
		// featured image leaves no trace in the content fingerprint, so a
		// rebuild cannot tell "we set this" from "somebody chose this" --
		// which makes never touching an occupied slot the only safe rule.
		$plan = $this->plan(
			$this->library(
				array( $this->image( 12, '', 'oferta-serwis.jpg' ) ),
				array( 61 => 999 )
			),
			$this->resolver( array( 'page:oferta' => ResourceResolution::reuse( 'page:oferta', 61, 'identical', true ) ) )
		);

		$this->assertSame( array(), $this->imageSteps( $plan ) );
	}

	public function test_a_reused_page_with_an_empty_slot_is_still_illustrated(): void {
		$plan = $this->plan(
			$this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ), array( 61 => 0 ) ),
			$this->resolver( array( 'page:oferta' => ResourceResolution::reuse( 'page:oferta', 61, 'identical', true ) ) )
		);

		$this->assertCount( 1, $this->imageSteps( $plan ) );
	}

	public function test_a_conflicted_page_is_not_illustrated_either(): void {
		// We declined to write its content because a human owns it. Changing
		// its featured image is the same intrusion through a smaller door.
		$plan = $this->plan(
			$this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ),
			$this->resolver( array( 'page:oferta' => ResourceResolution::conflict( 'page:oferta', 61, 'edited' ) ) )
		);

		$this->assertSame( array(), $this->imageSteps( $plan ) );
	}

	public function test_undoing_an_illustration_restores_the_page_never_deletes_the_image(): void {
		$plan  = $this->plan( $this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ) );
		$steps = $this->imageSteps( $plan );

		$this->assertSame( PlanAction::ROLLBACK_RESTORE_FEATURED_IMAGE, $steps[0]->rollbackStrategy );
		// Editing a page, not deleting media: the attachment is the user's.
		$this->assertSame( 'edit_pages', $steps[0]->requiredCapabilityForRollback() );
	}

	public function test_the_chosen_image_is_part_of_the_plan_hash(): void {
		// Which picture goes on which page is something the human approved;
		// it must not be swappable after approval.
		$a = $this->plan( $this->library( array( $this->image( 12, '', 'oferta-serwis.jpg' ) ) ) );
		$b = $this->plan( $this->library( array( $this->image( 99, '', 'oferta-serwis.jpg' ) ) ) );

		$this->assertNotSame( $a->planHash, $b->planHash );
	}
}
