/* global dosieciAiOperator */
( function () {
	'use strict';

	var form = document.getElementById( 'dosieci-ai-chat-form' );
	var input = document.getElementById( 'dosieci-ai-message' );
	var log = document.getElementById( 'dosieci-ai-chat-log' );

	if ( ! form || ! input || ! log ) {
		return;
	}

	/**
	 * Everything rendered here goes through textContent, never innerHTML:
	 * the model's answer is untrusted text and must never be parsed as
	 * markup in an admin page. The same applies to tool names and argument
	 * values echoed back in the confirmation card -- they originate from
	 * the model too.
	 */
	function appendTurn( label, text, modifier ) {
		var wrapper = document.createElement( 'div' );
		wrapper.className = 'dosieci-ai-turn dosieci-ai-turn--' + ( modifier || 'user' );

		var strong = document.createElement( 'strong' );
		strong.textContent = label;

		var body = document.createElement( 'p' );
		body.textContent = text;

		wrapper.appendChild( strong );
		wrapper.appendChild( body );
		log.appendChild( wrapper );
		log.scrollTop = log.scrollHeight;

		return wrapper;
	}

	function appendToolSummary( wrapper, toolCalls ) {
		if ( ! toolCalls || ! toolCalls.length ) {
			return;
		}

		var note = document.createElement( 'div' );
		note.className = 'dosieci-ai-tools';
		note.textContent = toolCalls
			.map( function ( call ) {
				var verb = 'ok' === call.outcome
					? dosieciAiOperator.strings.toolRan
					: dosieciAiOperator.strings.toolDenied;
				return verb + ': ' + call.tool + ( 'ok' === call.outcome ? '' : ' (' + call.outcome + ')' );
			} )
			.join( ' · ' );

		wrapper.appendChild( note );
	}

	function setBusy( busy ) {
		input.disabled = busy;

		if ( ! busy ) {
			input.focus();
		}
	}

	/**
	 * Renders one argument per row so the human can see exactly what they
	 * are approving. A confirmation prompt that only names the tool
	 * ("create_post?") is not informed consent -- the arguments are the
	 * part that decides what actually happens.
	 */
	function renderArguments( container, args ) {
		if ( ! args || 'object' !== typeof args ) {
			return;
		}

		var keys = Object.keys( args );
		if ( ! keys.length ) {
			return;
		}

		var list = document.createElement( 'dl' );
		list.className = 'dosieci-ai-args';

		keys.forEach( function ( key ) {
			var dt = document.createElement( 'dt' );
			dt.textContent = key;

			var dd = document.createElement( 'dd' );
			var value = args[ key ];
			dd.textContent = ( 'string' === typeof value )
				? value
				: JSON.stringify( value );

			list.appendChild( dt );
			list.appendChild( dd );
		} );

		container.appendChild( list );
	}

	function renderConfirmation( pending ) {
		var card = document.createElement( 'div' );
		card.className = 'dosieci-ai-confirm';

		var heading = document.createElement( 'strong' );
		heading.textContent = dosieciAiOperator.strings.confirmTitle;
		card.appendChild( heading );

		var what = document.createElement( 'p' );
		what.className = 'dosieci-ai-confirm__tool';
		what.textContent = pending.description
			? pending.description + ' (' + pending.tool_name + ')'
			: pending.tool_name;
		card.appendChild( what );

		renderArguments( card, pending.arguments );

		if ( 'destructive' === pending.risk_level ) {
			var warn = document.createElement( 'p' );
			warn.className = 'dosieci-ai-confirm__warning';
			warn.textContent = dosieciAiOperator.strings.confirmDestructive;
			card.appendChild( warn );
		}

		var actions = document.createElement( 'p' );

		var approve = document.createElement( 'button' );
		approve.type = 'button';
		approve.className = 'button button-primary';
		approve.textContent = dosieciAiOperator.strings.confirmApprove;

		var reject = document.createElement( 'button' );
		reject.type = 'button';
		reject.className = 'button';
		reject.textContent = dosieciAiOperator.strings.confirmReject;

		actions.appendChild( approve );
		actions.appendChild( document.createTextNode( ' ' ) );
		actions.appendChild( reject );
		card.appendChild( actions );

		log.appendChild( card );
		log.scrollTop = log.scrollHeight;

		function decide( approved ) {
			// Disable both buttons immediately: a double-click on "approve"
			// would otherwise send two confirmations for the same action,
			// and the second would fail as stale rather than being a no-op.
			approve.disabled = true;
			reject.disabled = true;

			var body = new FormData();
			body.append( 'action', 'dosieci_ai_operator_confirm' );
			body.append( 'nonce', dosieciAiOperator.nonce );
			body.append( 'tool_use_id', pending.tool_use_id );
			body.append( 'approved', approved ? '1' : '0' );

			var waiting = appendTurn(
				dosieciAiOperator.strings.operatorLabel,
				approved ? dosieciAiOperator.strings.applying : dosieciAiOperator.strings.thinking,
				'assistant'
			);

			send( body, waiting );
		}

		approve.addEventListener( 'click', function () {
			decide( true );
		} );

		reject.addEventListener( 'click', function () {
			decide( false );
		} );
	}

	/**
	 * One request/response cycle, shared by the message form and by the
	 * confirm/reject buttons so both handle a follow-up confirmation the
	 * same way -- a turn can pause more than once (build a page, then set
	 * it as the homepage), and each pause has to be presented identically.
	 */
	function send( body, placeholder ) {
		setBusy( true );

		fetch( dosieciAiOperator.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				var target = placeholder.querySelector( 'p' );

				if ( ! payload || ! payload.success || ! payload.data ) {
					target.textContent =
						( payload && payload.data && payload.data.message )
							? payload.data.message
							: dosieciAiOperator.strings.error;
					return;
				}

				if ( 'pending_confirmation' === payload.data.status ) {
					target.textContent = dosieciAiOperator.strings.confirmNeeded;
					appendToolSummary( placeholder, payload.data.tool_calls );
					renderConfirmation( payload.data.pending );
					return;
				}

				target.textContent = payload.data.answer;
				appendToolSummary( placeholder, payload.data.tool_calls );
			} )
			.catch( function () {
				placeholder.querySelector( 'p' ).textContent = dosieciAiOperator.strings.error;
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var message = input.value.trim();
		if ( ! message ) {
			return;
		}

		appendTurn( dosieciAiOperator.strings.youLabel, message, 'user' );
		input.value = '';

		var pending = appendTurn(
			dosieciAiOperator.strings.operatorLabel,
			dosieciAiOperator.strings.thinking,
			'assistant'
		);

		var body = new FormData();
		body.append( 'action', 'dosieci_ai_operator_chat' );
		body.append( 'nonce', dosieciAiOperator.nonce );
		body.append( 'message', message );

		send( body, pending );
	} );
}() );
