import $ from 'jquery';

$(function () {
    const $form = $('#chat-form');

    if (!$form.length) {
        return;
    }

    const $input = $('#chat-input');
    const $log = $('#chat-log');
    const $typing = $('#chat-typing');
    const $submit = $form.find('button[type="submit"]');
    const csrf = $('meta[name="csrf-token"]').attr('content');
    let conversationId = $form.data('conversation-id') || null;
    let busy = false;

    const scrollDown = () => $log.scrollTop($log[0].scrollHeight);

    const bubble = (classes, text) => $('<div>', { class: 'flex' + classes.wrap })
        .append($('<div>', { class: classes.box }).text(text));

    const addUser = (text) => {
        $log.append(bubble({
            wrap: ' justify-end',
            box: 'max-w-[80%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-indigo-600 px-4 py-2 text-white',
        }, text));
        scrollDown();
    };

    const addTools = (tools) => {
        const $row = $('<div>', { class: 'flex flex-wrap gap-2 justify-start' });
        tools.forEach((tool) => $row.append($('<span>', {
            class: 'inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800',
        }).text(tool.label)));
        $log.append($('<div class="flex justify-start">').append($row));
    };

    // O HTML vem do servidor, já escapado (Markdown com html_input=escape).
    const addAssistant = (html) => {
        $log.append($('<div class="flex justify-start">').append(
            $('<div>', {
                class: 'prose prose-sm max-w-[85%] rounded-2xl rounded-bl-sm bg-white px-4 py-2 shadow-sm ring-1 ring-slate-200',
            }).html(html),
        ));
        scrollDown();
    };

    const addError = (text) => {
        $log.append(bubble({
            wrap: ' justify-start',
            box: 'max-w-[80%] rounded-2xl bg-rose-50 px-4 py-2 text-sm text-rose-700 ring-1 ring-rose-200',
        }, text));
        scrollDown();
    };

    const errorText = (xhr) => {
        if (xhr.status === 429) {
            return 'Muitas perguntas em pouco tempo. Aguarde um minuto e tente novamente.';
        }
        if (xhr.status === 422) {
            const errors = xhr.responseJSON?.errors ?? {};
            return Object.values(errors).flat()[0] ?? 'Pergunta inválida.';
        }
        return 'Não foi possível consultar agora. Tente novamente.';
    };

    const send = (message) => {
        if (busy || !message) {
            return;
        }

        busy = true;
        addUser(message);
        $input.val('');
        $typing.removeClass('hidden');
        $submit.prop('disabled', true);
        scrollDown();

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: { message, conversation_id: conversationId },
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            dataType: 'json',
        })
            .done((res) => {
                conversationId = res.conversation_id;
                $form.data('conversation-id', conversationId);

                if (res.tools.length) {
                    addTools(res.tools);
                }
                addAssistant(res.answer.html);
            })
            .fail((xhr) => addError(errorText(xhr)))
            .always(() => {
                busy = false;
                $typing.addClass('hidden');
                $submit.prop('disabled', false);
                $input.trigger('focus');
            });
    };

    $form.on('submit', (event) => {
        event.preventDefault();
        send(String($input.val()).trim());
    });

    $input.on('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            $form.trigger('submit');
        }
    });

    $('.quick-prompt').on('click', function () {
        send($(this).data('prompt'));
    });

    scrollDown();
});
