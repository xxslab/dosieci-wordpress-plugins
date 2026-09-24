<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\TransportException;
use DoSieci\AiOperator\Plugin;

/**
 * The chat endpoint, plus the confirm/reject endpoint that completes a
 * turn the model paused for a human decision.
 *
 * Three independent checks run before anything happens, in this order:
 *   1. nonce  -- proves the request came from our own admin screen (CSRF)
 *   2. capability -- proves the user is actually allowed to use this
 *   3. gateway -- proves the site is configured (paired, or BYOK with a key)
 * A nonce alone is not authorisation, which is why 2 is not skipped just
 * because 1 passed.
 *
 * Note there is no logged-out (`wp_ajax_nopriv_`) variant registered. The
 * chat is an administrator tool; exposing it to anonymous visitors would
 * let anyone spend the site owner's AI credits -- or, with writes enabled,
 * rebuild the site.
 */
final class AjaxController {

	public const NONCE_ACTION   = 'dosieci_ai_operator_chat';
	public const AJAX_ACTION    = 'dosieci_ai_operator_chat';
	public const CONFIRM_ACTION = 'dosieci_ai_operator_confirm';

	private const HISTORY_OPTION    = 'dosieci_ai_operator_history';
	private const PENDING_OPTION    = 'dosieci_ai_operator_pending';
	private const HISTORY_MAX_TURNS = 40;

	public function __construct( private Plugin $plugin ) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handleChat' ) );
		add_action( 'wp_ajax_' . self::CONFIRM_ACTION, array( $this, 'handleConfirm' ) );
	}

	public function handleChat(): void {
		$this->assertAllowed();

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '';
		if ( '' === $message ) {
			wp_send_json_error( array( 'message' => __( 'Pusta wiadomość.', 'dosieci-ai-operator' ) ), 400 );
		}

		$this->respond(
			function () use ( $message ): array {
				return $this->plugin->chatSession()->send(
					$message,
					$this->loadConversation(),
					get_current_user_id()
				);
			}
		);
	}

	/**
	 * Completes a turn that stopped at a confirmation prompt.
	 *
	 * The pending action is read from server-side user meta, never from the
	 * POST body. If the browser supplied the tool name and arguments, a
	 * crafted request could confirm an action the user was never shown --
	 * the confirmation would be for one thing and the execution for
	 * another. The only thing the client gets to send is the decision and
	 * the id of the action it is deciding about.
	 */
	public function handleConfirm(): void {
		$this->assertAllowed();

		$pending = $this->loadPending();

		if ( null === $pending ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nie ma oczekującej akcji do zatwierdzenia.', 'dosieci-ai-operator' ),
					'code'    => 'no_pending_action',
				),
				409
			);
		}

		$toolUseId = isset( $_POST['tool_use_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tool_use_id'] ) ) : '';

		if ( ! hash_equals( (string) $pending['tool_use_id'], $toolUseId ) ) {
			// A mismatch means the screen is showing a stale action --
			// confirming it would run something the user is no longer
			// looking at.
			wp_send_json_error(
				array(
					'message' => __( 'Ta akcja jest już nieaktualna. Odśwież stronę.', 'dosieci-ai-operator' ),
					'code'    => 'stale_pending_action',
				),
				409
			);
		}

		$approved = isset( $_POST['approved'] ) && '1' === (string) $_POST['approved'];

		$this->clearPending();

		$this->respond(
			function () use ( $pending, $approved ): array {
				return $this->plugin->chatSession()->resume(
					$this->loadConversation(),
					(string) $pending['tool_name'],
					(string) $pending['tool_use_id'],
					is_array( $pending['arguments'] ) ? $pending['arguments'] : array(),
					$approved,
					get_current_user_id()
				);
			}
		);
	}

	/**
	 * Runs one chat/resume call and turns its result -- or its failure --
	 * into the JSON the chat screen expects. Shared by both endpoints so
	 * the error mapping and history handling cannot drift apart.
	 *
	 * @param callable():array<string, mixed> $turn
	 */
	private function respond( callable $turn ): void {
		try {
			$result = $turn();
		} catch ( HubException $e ) {
			// Graceful degradation: an AI outage must never break the rest
			// of wp-admin, so this is a JSON error the chat screen renders
			// inline, never a fatal.
			wp_send_json_error(
				array(
					'message'   => $this->humanise( $e ),
					'code'      => $e->errorCode,
					'retryable' => $e->retryable,
				),
				200
			);
		} catch ( TransportException $e ) {
			wp_send_json_error(
				array(
					'message'   => __( 'Nie udało się połączyć z usługą AI. Sprawdź połączenie sieciowe witryny.', 'dosieci-ai-operator' ),
					'code'      => 'transport_error',
					'retryable' => true,
				),
				200
			);
		}

		$this->saveConversation( $result['conversation'] );

		if ( 'pending_confirmation' === ( $result['status'] ?? '' ) ) {
			$this->savePending( $result['pending'] );

			wp_send_json_success(
				array(
					'status'     => 'pending_confirmation',
					'pending'    => $result['pending'],
					'tool_calls' => $result['tool_calls'],
				)
			);
		}

		wp_send_json_success(
			array(
				'status'     => 'answer',
				'answer'     => $result['answer'],
				'tool_calls' => $result['tool_calls'],
			)
		);
	}

	private function assertAllowed(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'dosieci-ai-operator' ) ), 403 );
		}
	}

	private function humanise( HubException $e ): string {
		return match ( $e->errorCode ) {
			'insufficient_credits'   => __( 'Wyczerpano kredyty AI w Twoim planie DoSieci. Doładuj je w panelu DoSieci lub przełącz wtyczkę na własny klucz API w Ustawieniach.', 'dosieci-ai-operator' ),
			'not_connected'          => __( 'Ta witryna nie jest połączona z DoSieci. Przejdź do zakładki Połączenie albo ustaw własny klucz API.', 'dosieci-ai-operator' ),
			'site_not_active'        => __( 'Połączenie tej witryny zostało unieważnione. Sparuj ją ponownie.', 'dosieci-ai-operator' ),
			'byok_key_missing'       => __( 'Wybrano własnego dostawcę AI, ale nie zapisano klucza API. Uzupełnij go w Ustawieniach.', 'dosieci-ai-operator' ),
			'byok_key_rejected'      => __( 'Dostawca AI odrzucił Twój klucz API. Sprawdź, czy jest poprawny i aktywny.', 'dosieci-ai-operator' ),
			'byok_quota_exhausted'   => __( 'Twoje konto u dostawcy AI nie ma środków. Doładuj je po stronie dostawcy.', 'dosieci-ai-operator' ),
			'provider_rate_limited'  => __( 'Zbyt wiele zapytań do modelu. Spróbuj ponownie za chwilę.', 'dosieci-ai-operator' ),
			'provider_bad_request'   => __( 'Dostawca AI odrzucił zapytanie. Sprawdź nazwę modelu w Ustawieniach.', 'dosieci-ai-operator' ),
			'provider_timeout',
			'provider_unavailable'   => __( 'Model AI jest chwilowo niedostępny. Spróbuj ponownie za chwilę.', 'dosieci-ai-operator' ),
			'ai_gateway_misconfigured' => __( 'Brama AI po stronie DoSieci jest niepoprawnie skonfigurowana. Skontaktuj się ze wsparciem.', 'dosieci-ai-operator' ),
			default                  => __( 'Błąd komunikacji z usługą AI.', 'dosieci-ai-operator' ),
		};
	}

	/**
	 * Chat history is per-user (a shop manager should not read the
	 * administrator's conversation) and bounded, so it cannot grow into a
	 * multi-megabyte option.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadConversation(): array {
		$stored = get_user_meta( get_current_user_id(), self::HISTORY_OPTION, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation
	 */
	private function saveConversation( array $conversation ): void {
		if ( count( $conversation ) > self::HISTORY_MAX_TURNS ) {
			$conversation = array_slice( $conversation, -self::HISTORY_MAX_TURNS );

			// Never start a stored conversation with an orphaned tool_result
			// or a tool_use whose result was trimmed away -- the provider
			// rejects both.
			while ( array() !== $conversation && in_array( $conversation[0]['role'] ?? '', array( 'tool_result', 'assistant_tool_use' ), true ) ) {
				array_shift( $conversation );
			}
		}

		update_user_meta( get_current_user_id(), self::HISTORY_OPTION, $conversation );
	}

	/**
	 * @param array<string, mixed> $pending
	 */
	private function savePending( array $pending ): void {
		update_user_meta( get_current_user_id(), self::PENDING_OPTION, $pending );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadPending(): ?array {
		$stored = get_user_meta( get_current_user_id(), self::PENDING_OPTION, true );

		return is_array( $stored ) && isset( $stored['tool_use_id'] ) ? $stored : null;
	}

	private function clearPending(): void {
		delete_user_meta( get_current_user_id(), self::PENDING_OPTION );
	}

	public static function clearHistoryForCurrentUser(): void {
		delete_user_meta( get_current_user_id(), self::HISTORY_OPTION );
		delete_user_meta( get_current_user_id(), self::PENDING_OPTION );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function historyForCurrentUser(): array {
		$stored = get_user_meta( get_current_user_id(), self::HISTORY_OPTION, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function pendingForCurrentUser(): ?array {
		$stored = get_user_meta( get_current_user_id(), self::PENDING_OPTION, true );

		return is_array( $stored ) && isset( $stored['tool_use_id'] ) ? $stored : null;
	}
}
