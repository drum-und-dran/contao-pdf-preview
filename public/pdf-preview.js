/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 * @license MIT
 *
 * Rendert PDF-Seiten im Browser (pdf.js), wenn der Server das auf dem
 * jeweiligen Hosting nicht kann. Den wichtigen Bereich bearbeitet Contaos
 * eigener Editor (Backend.editPreviewWizard) auf dem Vorschaubild.
 */
(function () {
    'use strict';

    if (window.dudPdfPreviewLoaded) {
        return;
    }

    window.dudPdfPreviewLoaded = true;

    function el(root, name) {
        return root.querySelector('[data-dud-pdfpreview-' + name + ']');
    }

    function message(root, text, isError) {
        var m = el(root, 'message');

        m.textContent = text;
        m.className = 'dud-pdfpreview__message ' + (isError ? 'tl_error' : 'tl_confirm');
    }

    function toBlob(canvas, type, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                blob ? resolve(blob) : reject(new Error('canvas.toBlob'));
            }, type, quality);
        });
    }

    function scaled(canvas, factor) {
        var copy = document.createElement('canvas');

        copy.width = Math.max(1, Math.round(canvas.width * factor));
        copy.height = Math.max(1, Math.round(canvas.height * factor));
        copy.getContext('2d').drawImage(canvas, 0, 0, copy.width, copy.height);

        return copy;
    }

    /*
     * Erzeugt ein JPEG, das unter das Upload-Limit des Servers passt. Zuerst wird
     * die Qualität gesenkt, danach die Auflösung (minimal 800 px kürzere Kante).
     */
    function encode(canvas, maxBytes) {
        var limit = Math.max(256 * 1024, Math.floor(maxBytes * 0.9) - 16 * 1024);
        var qualities = [0.92, 0.85, 0.75, 0.65];
        var minScale = Math.min(1, 800 / Math.min(canvas.width, canvas.height));

        function attempt(source, index) {
            return toBlob(source, 'image/jpeg', qualities[index]).then(function (blob) {
                if (blob.size <= limit) {
                    return blob;
                }

                if (index + 1 < qualities.length) {
                    return attempt(source, index + 1);
                }

                var factor = Math.max(Math.sqrt(limit / blob.size) * 0.95, 0.5);
                var nextScale = (source.width / canvas.width) * factor;

                if (nextScale < minScale) {
                    if (source.width / canvas.width <= minScale) {
                        return blob;
                    }

                    factor = minScale / (source.width / canvas.width);
                }

                return attempt(scaled(source, factor), 0);
            });
        }

        return attempt(canvas, 0);
    }

    function showImage(root, canvas) {
        var img = root.querySelector('.tl_edit_preview img');
        var box = Math.min(699 / canvas.width, 524 / canvas.height, 1);

        // Gleiche Darstellungsgröße wie im Contao-Standard (Box 699 × 524 px)
        img.width = Math.round(canvas.width * box);
        img.height = Math.round(canvas.height * box);
        img.hidden = false;
        img.src = canvas.toDataURL('image/jpeg', 0.85);
    }

    function render(root, force) {
        var d = root.dataset;

        if (d.busy) {
            return;
        }

        var pages = JSON.parse(d.pages || '[1]');
        var stored = JSON.parse(d.stored || '[]');
        var pageCount = parseInt(d.pageCount, 10) || 0;
        var target = parseInt(d.targetSize, 10) || 2048;

        var todo = pages.filter(function (page) {
            return (!pageCount || page <= pageCount) && (force || stored.indexOf(page) < 0);
        }).slice(0, 50);

        if (!todo.length) {
            return;
        }

        d.busy = '1';

        import(d.pdfjs).then(function (pdfjs) {
            pdfjs.GlobalWorkerOptions.workerSrc = d.worker;

            return pdfjs.getDocument({
                url: d.pdfUrl,
                withCredentials: true,
                isEvalSupported: false,
                enableXfa: false,
                stopAtErrors: true
            }).promise;
        }).then(function (pdf) {
            var chain = Promise.resolve();

            todo.forEach(function (pageNumber, index) {
                chain = chain.then(function () {
                    if (pageNumber > pdf.numPages) {
                        return null;
                    }

                    message(root, (d.msgRendering || '%s').replace('%s', pageNumber));

                    return pdf.getPage(pageNumber).then(function (page) {
                        var base = page.getViewport({ scale: 1 });
                        var scale = Math.min(target / Math.min(base.width, base.height), 8000 / Math.max(base.width, base.height));
                        var viewport = page.getViewport({ scale: scale });
                        var canvas = document.createElement('canvas');
                        var ctx = canvas.getContext('2d');

                        canvas.width = Math.floor(viewport.width);
                        canvas.height = Math.floor(viewport.height);
                        ctx.fillStyle = '#fff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);

                        return page.render({ canvasContext: ctx, viewport: viewport, annotationMode: 0 }).promise.then(function () {
                            if (index === 0) {
                                showImage(root, canvas);
                            }

                            return encode(canvas, parseInt(d.maxUpload, 10) || 2 * 1024 * 1024);
                        }).then(function (blob) {
                            var form = new FormData();

                            form.append('REQUEST_TOKEN', d.token);
                            form.append('path', d.path);
                            form.append('page', String(pageNumber));
                            form.append('image', blob, 'page-' + pageNumber + '.jpg');

                            return fetch(d.uploadUrl, {
                                method: 'POST',
                                body: form,
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                        }).then(function (response) {
                            return response.json().catch(function () {
                                return { ok: false, error: response.status + ' ' + response.statusText };
                            }).then(function (json) {
                                if (!response.ok || !json.ok) {
                                    throw new Error(json.error || response.statusText);
                                }

                                stored.push(pageNumber);
                            });
                        });
                    });
                });
            });

            return chain.then(function () {
                d.stored = JSON.stringify(stored);
                pdf.destroy();
            });
        }).then(function () {
            message(root, d.msgDone);

            if (d.source === 'none') {
                el(root, 'source').textContent = d.labelBrowser;
            }
        }).catch(function (error) {
            message(root, (d.msgFailed || '%s').replace('%s', error && error.message ? error.message : String(error)), true);
        }).finally(function () {
            delete d.busy;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-dud-pdfpreview-render]');

        if (button) {
            render(button.closest('[data-dud-pdfpreview]'), true);
        }
    });

    function init() {
        document.querySelectorAll('[data-dud-pdfpreview]').forEach(function (root) {
            if (root.dataset.initialized) {
                return;
            }

            root.dataset.initialized = '1';

            // Server kann nicht rendern → fehlende Seiten automatisch im Browser rendern
            if (root.dataset.source === 'none' || root.dataset.source === 'browser') {
                render(root, false);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('turbo:load', init);
    document.addEventListener('turbo:render', init);
    new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
})();
