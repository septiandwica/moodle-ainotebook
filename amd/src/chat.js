/**
 * AI Notebook Chat JS (Standard Moodle AJAX)
 * @copyright 2026 Tateta (samastanuswantara.com)
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    return {
        init: function(config) {
            var cmid = config.cmid;
            var sesskey = config.sesskey;
            var $messages = $('#chat-messages');
            var $input = $('#chat-input');
            var $sendBtn = $('#send-btn');

            function addMessage(text, type) {
                // Use .html() to allow AI to send formatted text/quizzes.
                var $msg = $('<div class="message"></div>').addClass(type).html(text.replace(/\n/g, '<br>'));
                $messages.append($msg);
                $messages.animate({ scrollTop: $messages[0].scrollHeight }, 300);
            }

            function sendMessage() {
                var message = $input.val().trim();
                if (message === '') return;

                console.log("AI Notebook: Sending message...");

                addMessage(message, 'user');
                $input.val('');
                $input.prop('disabled', true);
                $sendBtn.prop('disabled', true);

                // Show typing indicator.
                var $typing = $('<div class="message ai typing"><i>' + M.util.get_string('loading', 'admin') + '...</i></div>');
                $messages.append($typing);
                $messages.scrollTop($messages[0].scrollHeight);

                // Use Moodle Standard AJAX.
                $.ajax({
                    url: M.cfg.wwwroot + '/mod/ainotebook/chat_ajax.php',
                    type: 'POST',
                    data: {
                        cmid: cmid,
                        message: message,
                        sesskey: sesskey
                    },
                    success: function(data) {
                        $typing.remove();
                        try {
                            var res = typeof data === 'string' ? JSON.parse(data) : data;
                            if (res.success) {
                                addMessage(res.response, 'ai');
                            } else {
                                addMessage('Error: ' + res.error, 'ai');
                            }
                        } catch (e) {
                            addMessage('AI response was invalid.', 'ai');
                            console.error(data);
                        }
                    },
                    error: function(xhr, status, error) {
                        $typing.remove();
                        addMessage('Could not connect to AI service. (Status: ' + status + ')', 'ai');
                        console.error(error);
                    },
                    complete: function() {
                        $input.prop('disabled', false);
                        $sendBtn.prop('disabled', false);
                        $input.focus();
                    }
                });
            }

            $sendBtn.on('click', function(e) {
                e.preventDefault();
                sendMessage();
            });

            $input.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }
    };
});
