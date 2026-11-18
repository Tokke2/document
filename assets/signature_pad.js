(function (global) {
    'use strict';

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function smoothstep(value) {
        var t = clamp(value, 0, 1);
        return t * t * (3 - 2 * t);
    }

    function attach(canvas, options) {
        if (!canvas || typeof canvas.getContext !== 'function') return null;
        options = options && typeof options === 'object' ? options : {};

        function alignBackingStoreToDisplay() {
            var rect = canvas.getBoundingClientRect();
            var rectWidth = Number(rect.width) || 0;
            var rectHeight = Number(rect.height) || 0;
            if (rectWidth < 1 || rectHeight < 1 || canvas.height < 1) return;
            var displayAspect = rectWidth / rectHeight;
            var backingAspect = canvas.width / canvas.height;
            if (Math.abs(displayAspect / backingAspect - 1) < 0.025) return;
            var resolution = clamp(canvas.width / rectWidth, 1.5, 5);
            resolution = Math.min(resolution, Math.sqrt(1600000 / (rectWidth * rectHeight)), 2400 / rectWidth, 1800 / rectHeight);
            resolution = Math.max(1, resolution);
            canvas.width = Math.max(1, Math.round(rectWidth * resolution));
            canvas.height = Math.max(1, Math.round(rectHeight * resolution));
        }
        alignBackingStoreToDisplay();

        var context;
        try {
            context = canvas.getContext('2d');
        } catch (ignore) {
            return null;
        }
        if (!context) return null;

        var inkColor = typeof options.color === 'string' ? options.color : '#18385f';
        var background = Object.prototype.hasOwnProperty.call(options, 'background') ? options.background : null;
        if (background === false) background = null;
        var basePadding = Number.isFinite(Number(options.basePadding)) ? Math.max(0, Number(options.basePadding)) : 40;
        var strokes = [];
        var baseImage = null;
        var activeStroke = null;
        var activePointerId = null;
        var renderPending = false;
        var pressureCurve = options.pressureCurve === 'soft' || options.pressureCurve === 'firm'
            ? options.pressureCurve
            : 'linear';
        var defaultCropPaddingCssPx = Number.isFinite(Number(options.cropPaddingCssPx))
            ? clamp(Number(options.cropPaddingCssPx), 0, 24)
            : 8;
        var lastStrokeDiagnostics = null;
        var lastCropMetrics = null;
        var longGapMs = 100;
        var longGapDistanceCss = 26;

        function canvasScale(rect) {
            rect = rect || canvas.getBoundingClientRect();
            var rectWidth = Math.max(1, Number(rect.width) || canvas.width || 1);
            var rectHeight = Math.max(1, Number(rect.height) || canvas.height || 1);
            var scaleX = Math.max(0.01, canvas.width / rectWidth);
            var scaleY = Math.max(0.01, canvas.height / rectHeight);
            return { x: scaleX, y: scaleY, mean: Math.sqrt(scaleX * scaleY), width: rectWidth, height: rectHeight };
        }

        function eventTime(event) {
            var time = Number(event && event.timeStamp);
            if (Number.isFinite(time) && time >= 0) return time;
            if (global.performance && typeof global.performance.now === 'function') return global.performance.now();
            return Date.now();
        }

        function createStroke(event) {
            var pointerType = event && typeof event.pointerType === 'string' && event.pointerType !== ''
                ? event.pointerType
                : 'mouse';
            var contactWidth = Number(event && event.width);
            var contactHeight = Number(event && event.height);
            return {
                points: [],
                pointerType: pointerType,
                pressureMode: pointerType === 'pen' ? 'pending' : 'not-applicable',
                pressureMin: 1,
                pressureMax: 0,
                pressureSamples: 0,
                eventSamples: 0,
                coalescedSamples: 0,
                intervalTotalMs: 0,
                intervalCount: 0,
                maxGapMs: 0,
                longGapCount: 0,
                nonMonotonicTimestamps: 0,
                lastInputTime: null,
                filteredSpeed: 0,
                pointerCaptureLost: false,
                pointerCancelled: false,
                palmStrokeDiscarded: false,
                palmCandidate: pointerType === 'touch' && Number.isFinite(contactWidth) && Number.isFinite(contactHeight)
                    && Math.max(contactWidth, contactHeight) >= 42,
            };
        }

        function observeInput(event, coalesced) {
            if (!activeStroke) return;
            var time = eventTime(event);
            activeStroke.eventSamples++;
            if (coalesced) activeStroke.coalescedSamples++;
            if (activeStroke.lastInputTime !== null) {
                var delta = time - activeStroke.lastInputTime;
                if (delta <= 0) {
                    activeStroke.nonMonotonicTimestamps++;
                    time = activeStroke.lastInputTime + 1;
                    delta = 1;
                }
                activeStroke.intervalTotalMs += delta;
                activeStroke.intervalCount++;
                activeStroke.maxGapMs = Math.max(activeStroke.maxGapMs, delta);
                if (delta > longGapMs) activeStroke.longGapCount++;
            }
            activeStroke.lastInputTime = time;

            if (activeStroke.pointerType !== 'pen' || !event || event.type === 'pointerup'
                || event.type === 'pointercancel' || event.type === 'lostpointercapture') return;
            var pressure = Number(event.pressure);
            if (!Number.isFinite(pressure)) return;
            pressure = clamp(pressure, 0, 1);
            activeStroke.pressureMin = Math.min(activeStroke.pressureMin, pressure);
            activeStroke.pressureMax = Math.max(activeStroke.pressureMax, pressure);
            activeStroke.pressureSamples++;
            var pressureRange = activeStroke.pressureMax - activeStroke.pressureMin;
            if (activeStroke.pressureSamples >= 4) {
                if (activeStroke.pressureMode === 'pending') {
                    activeStroke.pressureMode = pressureRange >= 0.04 ? 'variable' : 'flat';
                } else if (activeStroke.pressureMode === 'flat' && pressureRange >= 0.065) {
                    activeStroke.pressureMode = 'variable';
                }
            }
        }

        function strokeDiagnostics(stroke) {
            if (!stroke) return null;
            var pressureRange = stroke.pressureSamples > 0 ? stroke.pressureMax - stroke.pressureMin : null;
            var averageInterval = stroke.intervalCount > 0 ? stroke.intervalTotalMs / stroke.intervalCount : null;
            return Object.freeze({
                pointerType: stroke.pointerType,
                pressureMode: stroke.pressureMode,
                pressureSamples: stroke.pressureSamples,
                pressureMin: stroke.pressureSamples > 0 ? stroke.pressureMin : null,
                pressureMax: stroke.pressureSamples > 0 ? stroke.pressureMax : null,
                pressureRange: pressureRange,
                eventSamples: stroke.eventSamples,
                coalescedSamples: stroke.coalescedSamples,
                averageIntervalMs: averageInterval,
                approximateHz: averageInterval && averageInterval > 0 ? 1000 / averageInterval : null,
                maxGapMs: stroke.intervalCount > 0 ? stroke.maxGapMs : null,
                longGapCount: stroke.longGapCount,
                nonMonotonicTimestamps: stroke.nonMonotonicTimestamps,
                pointerCaptureLost: !!stroke.pointerCaptureLost,
                pointerCancelled: !!stroke.pointerCancelled,
                palmStrokeDiscarded: !!stroke.palmStrokeDiscarded,
            });
        }

        function eventPoint(event) {
            var rect = canvas.getBoundingClientRect();
            var scale = canvasScale(rect);
            var clientX = Number(event.clientX);
            var clientY = Number(event.clientY);
            if (!Number.isFinite(clientX)) clientX = rect.left;
            if (!Number.isFinite(clientY)) clientY = rect.top;
            var time = eventTime(event);
            var pressure = Number(event.pressure);
            if (!Number.isFinite(pressure)) pressure = 0;
            var tiltX = Number(event.tiltX);
            var tiltY = Number(event.tiltY);
            if (!Number.isFinite(tiltX)) tiltX = 0;
            if (!Number.isFinite(tiltY)) tiltY = 0;
            return {
                x: clamp((clientX - rect.left) * scale.x, 0, canvas.width),
                y: clamp((clientY - rect.top) * scale.y, 0, canvas.height),
                clientX: clientX,
                clientY: clientY,
                time: time,
                pressure: clamp(pressure, 0, 1),
                tiltX: clamp(tiltX, -90, 90),
                tiltY: clamp(tiltY, -90, 90),
                pointerType: typeof event.pointerType === 'string' ? event.pointerType : 'mouse',
                scale: scale
            };
        }

        function shapedPressure(value) {
            value = clamp(value, 0, 1);
            if (pressureCurve === 'soft') return Math.pow(value, 0.85);
            if (pressureCurve === 'firm') return Math.pow(value, 1.15);
            return value;
        }

        function makePoint(event) {
            var point = eventPoint(event);
            var activePoints = activeStroke ? activeStroke.points : [];
            var previous = activePoints.length ? activePoints[activePoints.length - 1] : null;
            if (previous && event.type === 'pointerup' && point.pointerType === 'pen' && point.pressure === 0) {
                point.pressure = previous.pressure;
                point.tiltX = previous.tiltX;
                point.tiltY = previous.tiltY;
            }
            var baseWidth = 1.9 * point.scale.mean;
            var targetWidth;
            var speed = activeStroke ? activeStroke.filteredSpeed : 0;
            var elapsed = 0;
            var distanceCss = 0;

            if (previous) {
                var rawElapsed = point.time - previous.time;
                if (rawElapsed <= 0) {
                    point.time = previous.time + 1;
                    rawElapsed = 1;
                }
                elapsed = rawElapsed;
                var dxCss = point.clientX - previous.clientX;
                var dyCss = point.clientY - previous.clientY;
                distanceCss = Math.sqrt(dxCss * dxCss + dyCss * dyCss);
                point.breakBefore = elapsed > longGapMs && distanceCss > longGapDistanceCss;

                var speedElapsed = clamp(elapsed, 1, 40);
                var instantSpeed = distanceCss / speedElapsed;
                if (elapsed > longGapMs && activeStroke.filteredSpeed > 0) {
                    speed = activeStroke.filteredSpeed;
                } else {
                    speed = instantSpeed;
                    activeStroke.filteredSpeed = activeStroke.filteredSpeed > 0
                        ? activeStroke.filteredSpeed * 0.65 + instantSpeed * 0.35
                        : instantSpeed;
                }

                // Stabilize only slow, nearly straight motion; cap the correction to 0.35 CSS px per sample.
                if (event.type === 'pointermove' && !point.breakBefore) {
                    var cornerFactor = 1;
                    if (activePoints.length > 1) {
                        var older = activePoints[activePoints.length - 2];
                        var ax = previous.clientX - older.clientX;
                        var ay = previous.clientY - older.clientY;
                        var bx = point.clientX - previous.clientX;
                        var by = point.clientY - previous.clientY;
                        var aLength = Math.sqrt(ax * ax + ay * ay);
                        var bLength = Math.sqrt(bx * bx + by * by);
                        if (aLength > 0.1 && bLength > 0.1) {
                            var directionCosine = clamp((ax * bx + ay * by) / (aLength * bLength), -1, 1);
                            cornerFactor = clamp((directionCosine + 1) * 0.5, 0, 1);
                        }
                    }
                    var slowMovement = 1 - clamp(speed / 0.9, 0, 1);
                    var positionSmoothing = 0.14 * slowMovement * cornerFactor;
                    var maxCorrectionX = 0.35 * point.scale.x;
                    var maxCorrectionY = 0.35 * point.scale.y;
                    var correctionX = clamp((point.x - previous.x) * positionSmoothing, -maxCorrectionX, maxCorrectionX);
                    var correctionY = clamp((point.y - previous.y) * positionSmoothing, -maxCorrectionY, maxCorrectionY);
                    point.x -= correctionX;
                    point.y -= correctionY;
                }
            }

            var speedFactor = clamp(speed / 2.4, 0, 1);
            var tiltAmount = Math.sqrt(point.tiltX * point.tiltX + point.tiltY * point.tiltY) / 127.279;
            var tiltScale = 1 + clamp(tiltAmount, 0, 1) * 0.035;
            if (point.pointerType === 'pen') {
                var penPressure = point.pressure > 0 ? point.pressure : 0.38;
                var pressureFallback = activeStroke && activeStroke.pressureMode === 'flat';
                targetWidth = pressureFallback
                    ? baseWidth * (1.11 - speedFactor * 0.24) * tiltScale
                    : baseWidth * (0.54 + 0.78 * shapedPressure(penPressure)) * tiltScale;
            } else if (previous) {
                targetWidth = baseWidth * (1.11 - speedFactor * 0.24);
            } else {
                targetWidth = baseWidth * 0.82;
            }

            if (!previous || point.breakBefore) {
                point.width = targetWidth;
            } else {
                var widthElapsed = clamp(elapsed, 1, 24);
                var widthAlpha = clamp(1 - Math.exp(-widthElapsed / 18), 0.08, 0.62);
                point.width = previous.width + (targetWidth - previous.width) * widthAlpha;
            }
            point.speedCssPerMs = speed;
            return point;
        }

        function addEventPoint(event, force, coalesced) {
            if (!activeStroke) return;
            observeInput(event, !!coalesced);
            var point = makePoint(event);
            var points = activeStroke.points;
            var previous = points.length ? points[points.length - 1] : null;
            if (previous && !force) {
                var dxCss = point.clientX - previous.clientX;
                var dyCss = point.clientY - previous.clientY;
                var distance = Math.sqrt(dxCss * dxCss + dyCss * dyCss);
                var elapsed = Math.max(0, point.time - previous.time);
                var pressureChanged = Math.abs(point.pressure - previous.pressure) > 0.035;
                if (distance < 0.28 && elapsed < 3 && !pressureChanged) return;
            }
            points.push(point);
        }

        function cubic(a, b, c, d, t) {
            var inverse = 1 - t;
            return inverse * inverse * inverse * a
                + 3 * inverse * inverse * t * b
                + 3 * inverse * t * t * c
                + t * t * t * d;
        }

        function smoothSamples(points, scale) {
            if (!points.length) return [];
            if (points.length === 1) {
                return [{ x: points[0].x, y: points[0].y, width: points[0].width }];
            }

            var samples = [];
            for (var i = 0; i < points.length - 1; i++) {
                var p0 = points[Math.max(0, i - 1)];
                var p1 = points[i];
                var p2 = points[i + 1];
                var p3 = points[Math.min(points.length - 1, i + 2)];
                var c1x = p1.x + (p2.x - p0.x) / 6;
                var c1y = p1.y + (p2.y - p0.y) / 6;
                var c2x = p2.x - (p3.x - p1.x) / 6;
                var c2y = p2.y - (p3.y - p1.y) / 6;
                var segmentCss = Math.sqrt(
                    Math.pow((p2.x - p1.x) / scale.x, 2) +
                    Math.pow((p2.y - p1.y) / scale.y, 2)
                );
                var steps = clamp(Math.ceil(segmentCss / 2.1), 1, 36);

                for (var step = 0; step < steps; step++) {
                    var t = step / steps;
                    samples.push({
                        x: cubic(p1.x, c1x, c2x, p2.x, t),
                        y: cubic(p1.y, c1y, c2y, p2.y, t),
                        width: p1.width + (p2.width - p1.width) * t
                    });
                }
            }
            var last = points[points.length - 1];
            samples.push({ x: last.x, y: last.y, width: last.width });
            return samples;
        }

        function drawStroke(stroke, complete, scale) {
            if (!stroke || !stroke.points || stroke.points.length === 0) return;
            var segment = [];
            for (var i = 0; i < stroke.points.length; i++) {
                var point = stroke.points[i];
                if (point.breakBefore && segment.length > 0) {
                    drawStrokeSegment({ points: segment, pointerType: stroke.pointerType, pressureMode: stroke.pressureMode }, true, scale);
                    segment = [];
                }
                segment.push(point);
            }
            if (segment.length > 0) {
                drawStrokeSegment({ points: segment, pointerType: stroke.pointerType, pressureMode: stroke.pressureMode }, complete, scale);
            }
        }

        function drawStrokeSegment(stroke, complete, scale) {
            if (!stroke || !stroke.points || stroke.points.length === 0) return;
            var samples = smoothSamples(stroke.points, scale);
            if (!samples.length) return;

            if (samples.length === 1) {
                context.beginPath();
                context.arc(samples[0].x, samples[0].y, Math.max(0.5, samples[0].width * 0.5), 0, Math.PI * 2);
                context.fillStyle = inkColor;
                context.fill();
                return;
            }

            var distances = [0];
            for (var i = 1; i < samples.length; i++) {
                var dx = (samples[i].x - samples[i - 1].x) / scale.x;
                var dy = (samples[i].y - samples[i - 1].y) / scale.y;
                distances[i] = distances[i - 1] + Math.sqrt(dx * dx + dy * dy);
            }
            var totalDistance = distances[distances.length - 1];
            var minimumWidth = scale.mean * 0.14;

            // Tiny marks should stay legible instead of being pinched by the normal start/end taper.
            if (totalDistance < 1.6) {
                var shortWidth = 0;
                for (var shortIndex = 0; shortIndex < samples.length; shortIndex++) {
                    shortWidth += samples[shortIndex].width;
                }
                shortWidth = Math.max(minimumWidth, shortWidth / samples.length);

                context.save();
                context.strokeStyle = inkColor;
                context.fillStyle = inkColor;
                context.globalAlpha = 1;
                context.lineCap = 'round';
                context.lineJoin = 'round';
                if (totalDistance < 0.08) {
                    var dot = samples[Math.floor(samples.length / 2)];
                    context.beginPath();
                    context.arc(dot.x, dot.y, Math.max(0.5, shortWidth * 0.5), 0, Math.PI * 2);
                    context.fill();
                } else {
                    context.beginPath();
                    context.moveTo(samples[0].x, samples[0].y);
                    var lastShortSample = samples[samples.length - 1];
                    context.lineTo(lastShortSample.x, lastShortSample.y);
                    context.lineWidth = shortWidth;
                    context.stroke();
                }
                context.restore();
                return;
            }

            // Let contact pressure subtly shape the taper without turning the ballpoint into a brush.
            var firstPoint = stroke.points[0];
            var lastPoint = stroke.points[stroke.points.length - 1];
            var startPressure = firstPoint && firstPoint.pressure > 0 ? firstPoint.pressure : 0.38;
            var endPressure = lastPoint && lastPoint.pressure > 0 ? lastPoint.pressure : 0.38;
            var startFloor = 0.20 + 0.14 * clamp(startPressure, 0, 1);
            var endFloor = 0.20 + 0.14 * clamp(endPressure, 0, 1);
            var startTaper = Math.max(1.5, Math.min(6.5, totalDistance * 0.30));
            var endTaper = Math.max(1.5, Math.min(7.5, totalDistance * 0.34));
            var widths = [];
            for (var j = 0; j < samples.length; j++) {
                var startFactor = startFloor + (1 - startFloor) * smoothstep(distances[j] / startTaper);
                var endFactor = complete
                    ? endFloor + (1 - endFloor) * smoothstep((totalDistance - distances[j]) / endTaper)
                    : 1;
                widths[j] = Math.max(minimumWidth, samples[j].width * Math.min(startFactor, endFactor));
            }

            context.save();
            context.strokeStyle = inkColor;
            context.fillStyle = inkColor;
            context.globalAlpha = 1;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            for (var k = 1; k < samples.length; k++) {
                context.beginPath();
                context.moveTo(samples[k - 1].x, samples[k - 1].y);
                context.lineTo(samples[k].x, samples[k].y);
                context.lineWidth = Math.max(minimumWidth, (widths[k - 1] + widths[k]) / 2);
                context.stroke();
            }
            context.restore();
        }

        function drawBaseImage() {
            if (!baseImage || !(baseImage.naturalWidth || baseImage.width) || !(baseImage.naturalHeight || baseImage.height)) return;
            var imageWidth = baseImage.naturalWidth || baseImage.width;
            var imageHeight = baseImage.naturalHeight || baseImage.height;
            var availableWidth = Math.max(1, canvas.width - basePadding * 2);
            var availableHeight = Math.max(1, canvas.height - basePadding * 2);
            var factor = Math.min(availableWidth / imageWidth, availableHeight / imageHeight);
            var width = imageWidth * factor;
            var height = imageHeight * factor;
            context.drawImage(baseImage, (canvas.width - width) / 2, (canvas.height - height) / 2, width, height);
        }

        function redraw() {
            renderPending = false;
            var scale = canvasScale();
            context.save();
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.clearRect(0, 0, canvas.width, canvas.height);
            if (background !== null && background !== '') {
                context.fillStyle = background;
                context.fillRect(0, 0, canvas.width, canvas.height);
            }
            context.imageSmoothingEnabled = true;
            if ('imageSmoothingQuality' in context) context.imageSmoothingQuality = 'high';
            drawBaseImage();
            for (var i = 0; i < strokes.length; i++) drawStroke(strokes[i], true, scale);
            if (activeStroke) drawStroke(activeStroke, false, scale);
            context.restore();
        }

        function scheduleRedraw() {
            if (renderPending) return;
            renderPending = true;
            var raf = typeof global.requestAnimationFrame === 'function'
                ? global.requestAnimationFrame.bind(global)
                : function (callback) { return global.setTimeout(callback, 16); };
            raf(redraw);
        }

        function releaseCapture(pointerId) {
            try {
                if (pointerId !== null && canvas.hasPointerCapture(pointerId)) canvas.releasePointerCapture(pointerId);
            } catch (ignore) {}
        }

        function discardPalmStroke() {
            if (!activeStroke) return;
            activeStroke.palmStrokeDiscarded = true;
            lastStrokeDiagnostics = strokeDiagnostics(activeStroke);
            var previousPointerId = activePointerId;
            activeStroke = null;
            activePointerId = null;
            releaseCapture(previousPointerId);
            redraw();
        }

        function pointerDown(event) {
            var replacePalmStroke = !!(activeStroke && event.pointerType === 'pen'
                && activeStroke.pointerType === 'touch' && activeStroke.palmCandidate);
            if (activeStroke && !replacePalmStroke) return;
            if (event.isPrimary === false && !replacePalmStroke) return;
            if (event.pointerType === 'mouse' && event.button !== undefined && event.button !== 0) return;
            if (replacePalmStroke) discardPalmStroke();
            if (event.cancelable) event.preventDefault();
            activePointerId = event.pointerId;
            activeStroke = createStroke(event);
            activeStroke.palmStrokeDiscarded = replacePalmStroke;
            addEventPoint(event, true, false);
            if (typeof options.onStart === 'function') options.onStart(event);
            try { canvas.setPointerCapture(event.pointerId); } catch (ignore) {}
            redraw();
        }

        function pointerMove(event) {
            if (!activeStroke || event.pointerId !== activePointerId) return;
            if (event.cancelable) event.preventDefault();
            var samples = [];
            if (typeof event.getCoalescedEvents === 'function') {
                try { samples = event.getCoalescedEvents(); } catch (ignore) { samples = []; }
            }
            var usedCoalescedEvents = !!(samples && samples.length > 0);
            if (!samples || samples.length === 0) samples = [event];
            for (var i = 0; i < samples.length; i++) {
                addEventPoint(samples[i], false, usedCoalescedEvents && samples[i] !== event);
            }
            scheduleRedraw();
        }

        function finishStroke(event) {
            if (!activeStroke) return;
            if (event && event.pointerId !== undefined && event.pointerId !== activePointerId) return;
            if (event && event.type === 'pointerup') addEventPoint(event, true, false);
            if (event && event.type === 'lostpointercapture') activeStroke.pointerCaptureLost = true;
            if (event && event.type === 'pointercancel') activeStroke.pointerCancelled = true;
            if (event && event.cancelable) event.preventDefault();
            var finishedStroke = activeStroke;
            activeStroke = null;
            strokes.push(finishedStroke);
            lastStrokeDiagnostics = strokeDiagnostics(finishedStroke);
            releaseCapture(activePointerId);
            activePointerId = null;
            redraw();
            if (typeof options.onEnd === 'function') options.onEnd(event || null, lastStrokeDiagnostics);
        }

        function clear() {
            releaseCapture(activePointerId);
            strokes = [];
            activeStroke = null;
            activePointerId = null;
            baseImage = null;
            lastStrokeDiagnostics = null;
            lastCropMetrics = null;
            redraw();
        }

        function setBaseImage(image) {
            baseImage = image || null;
            redraw();
        }

        function serialize() {
            redraw();
            try { return canvas.toDataURL('image/png'); }
            catch (ignore) { return ''; }
        }

        function serializeCropped(padding) {
            redraw();
            lastCropMetrics = null;
            var pixels;
            try { pixels = context.getImageData(0, 0, canvas.width, canvas.height).data; }
            catch (ignore) { return ''; }
            var minX = canvas.width;
            var minY = canvas.height;
            var maxX = -1;
            var maxY = -1;
            for (var y = 0; y < canvas.height; y++) {
                for (var x = 0; x < canvas.width; x++) {
                    var offset = (y * canvas.width + x) * 4;
                    if (pixels[offset + 3] > 8 && (pixels[offset] < 245 || pixels[offset + 1] < 245 || pixels[offset + 2] < 245)) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }
            if (maxX < minX || maxY < minY) return '';
            var inset = Number.isFinite(Number(padding))
                ? Math.max(0, Math.round(Number(padding)))
                : Math.round(defaultCropPaddingCssPx * canvasScale().mean);
            var left = Math.max(0, minX - inset);
            var top = Math.max(0, minY - inset);
            var right = Math.min(canvas.width - 1, maxX + inset);
            var bottom = Math.min(canvas.height - 1, maxY + inset);
            var width = right - left + 1;
            var height = bottom - top + 1;
            lastCropMetrics = Object.freeze({
                imageWidthPx: width,
                imageHeightPx: height,
                inkWidthPx: maxX - minX + 1,
                inkHeightPx: maxY - minY + 1,
                padLeftPx: minX - left,
                padTopPx: minY - top,
                padRightPx: right - maxX,
                padBottomPx: bottom - maxY,
                requestedPaddingPx: inset,
            });
            var output = document.createElement('canvas');
            output.width = width;
            output.height = height;
            var outputContext = output.getContext('2d');
            if (!outputContext) {
                lastCropMetrics = null;
                return '';
            }
            if (background !== null && background !== '') {
                outputContext.fillStyle = background;
                outputContext.fillRect(0, 0, width, height);
            }
            outputContext.imageSmoothingEnabled = true;
            if ('imageSmoothingQuality' in outputContext) outputContext.imageSmoothingQuality = 'high';
            outputContext.drawImage(canvas, left, top, width, height, 0, 0, width, height);
            try { return output.toDataURL('image/png'); }
            catch (ignore) {
                lastCropMetrics = null;
                return '';
            }
        }

        function syncBackingStoreToDisplay() {
            var rect = canvas.getBoundingClientRect();
            var rectWidth = Number(rect.width) || 0;
            var rectHeight = Number(rect.height) || 0;
            if (rectWidth < 1 || rectHeight < 1 || canvas.height < 1) return;
            var displayAspect = rectWidth / rectHeight;
            var backingAspect = canvas.width / canvas.height;
            if (Math.abs(displayAspect / backingAspect - 1) < 0.025) return;

            var resolution = clamp(canvas.width / rectWidth, 1.5, 5);
            resolution = Math.min(resolution, Math.sqrt(1600000 / (rectWidth * rectHeight)), 2400 / rectWidth, 1800 / rectHeight);
            resolution = Math.max(1, resolution);
            var newWidth = Math.max(1, Math.round(rectWidth * resolution));
            var newHeight = Math.max(1, Math.round(rectHeight * resolution));
            var scaleX = newWidth / canvas.width;
            var scaleY = newHeight / canvas.height;
            var scaleWidth = Math.sqrt(scaleX * scaleY);

            function rescaleStroke(stroke) {
                if (!stroke || !stroke.points) return;
                for (var i = 0; i < stroke.points.length; i++) {
                    stroke.points[i].x *= scaleX;
                    stroke.points[i].y *= scaleY;
                    stroke.points[i].width *= scaleWidth;
                }
            }
            for (var s = 0; s < strokes.length; s++) rescaleStroke(strokes[s]);
            rescaleStroke(activeStroke);
            canvas.width = newWidth;
            canvas.height = newHeight;
            redraw();
        }

        function scheduleBackingStoreSync() {
            var raf = typeof global.requestAnimationFrame === 'function'
                ? global.requestAnimationFrame.bind(global)
                : function (callback) { return global.setTimeout(callback, 16); };
            raf(syncBackingStoreToDisplay);
        }

        canvas.style.touchAction = 'none';
        canvas.addEventListener('pointerdown', pointerDown);
        canvas.addEventListener('pointermove', pointerMove);
        canvas.addEventListener('pointerup', finishStroke);
        canvas.addEventListener('pointercancel', finishStroke);
        canvas.addEventListener('lostpointercapture', finishStroke);
        if (typeof global.addEventListener === 'function') global.addEventListener('resize', scheduleBackingStoreSync);
        redraw();

        return {
            context: context,
            clear: clear,
            serialize: serialize,
            serializeCropped: serializeCropped,
            setBaseImage: setBaseImage,
            getLastStrokeDiagnostics: function () { return lastStrokeDiagnostics; },
            getLastCropMetrics: function () { return lastCropMetrics; },
            get hasInk() { return strokes.length > 0 || !!activeStroke || !!baseImage; }
        };
    }

    global.TadegaSignaturePad = Object.freeze({ attach: attach });
}(window));
