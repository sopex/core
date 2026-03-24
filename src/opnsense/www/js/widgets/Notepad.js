/*
 * Copyright (C) 2026 Konstantinos Spartalis (cspartalis@potatonetworks.com)
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

export default class Notepad extends BaseWidget {
    constructor(config) {
        super(config);
    }

    getMarkup() {
        let $container = $(`
            <div id="notepad-container-${this.id}" class="widget-content">
                <div style="padding: 10px; display: flex; flex-direction: column; height: 100%;">
                    <textarea id="notepad-text-${this.id}" maxlength="8192" style="width: 100%; resize: none; min-height: 80px; flex-grow: 1; margin-bottom: 10px;"></textarea>
                    <div style="display: flex; justify-content: flex-end; align-items: center;">
                        <span id="notepad-error-msg-${this.id}" style="color: red; margin-right: 10px; display: none;"><i class="fa fa-exclamation-circle"></i> ${this.translations.error}</span>
                        <span id="notepad-saved-msg-${this.id}" style="color: green; margin-right: 10px; display: none;"><i class="fa fa-check"></i> ${this.translations.saved}</span>
                        <button id="notepad-save-btn-${this.id}" class="btn btn-primary btn-sm">${this.translations.save}</button>
                    </div>
                </div>
            </div>
        `);
        return $container;
    }

    async onMarkupRendered() {
        const textElement = $(`#notepad-text-${this.id}`);
        const saveButton = $(`#notepad-save-btn-${this.id}`);
        const savedMsg = $(`#notepad-saved-msg-${this.id}`);
        const errorMsg = $(`#notepad-error-msg-${this.id}`);

        const data = await this.ajaxCall('/api/core/dashboard/getNote');
        if (data.result === 'ok') {
            textElement.val(data.note);
        }

        const container = document.getElementById(`notepad-container-${this.id}`);
        const btnRow = container.querySelector('div > div:last-child');
        const observer = new ResizeObserver(() => {
            const available = container.clientHeight - btnRow.offsetHeight - 20;
            textElement.css('height', Math.max(80, available) + 'px');
        });
        observer.observe(container);

        $(saveButton).on('click', async () => {
            $(savedMsg).hide();
            $(errorMsg).hide();
            $(saveButton).prop('disabled', true);
            const result = await this.ajaxCall('/api/core/dashboard/saveNote', JSON.stringify({
                note: textElement.val()
            }), 'POST');

            $(saveButton).prop('disabled', false);
            if (result.result === 'saved') {
                $(savedMsg).fadeIn().delay(2000).fadeOut();
            } else {
                $(errorMsg).fadeIn().delay(3000).fadeOut();
            }
        });
    }
}
