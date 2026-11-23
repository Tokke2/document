/* Local, conservative parsing of Swedish invoice suggestions. No audio is sent by this file. */
(function (root) {
    'use strict';
    var ones = { noll: 0, en: 1, ett: 1, två: 2, tre: 3, fyra: 4, fem: 5, sex: 6, sju: 7, åtta: 8, nio: 9, tio: 10, elva: 11, tolv: 12, tretton: 13, fjorton: 14, femton: 15, sexton: 16, sjutton: 17, arton: 18, nitton: 19 };
    var tens = { tjugo: 20, trettio: 30, fyrtio: 40, femtio: 50, sextio: 60, sjuttio: 70, åttio: 80, nittio: 90 };
    function integerWords(text) {
        if (Object.prototype.hasOwnProperty.call(ones, text)) return ones[text];
        if (Object.prototype.hasOwnProperty.call(tens, text)) return tens[text];
        // In written Swedish "ettusen" shares a t with "tusen".
        if (text.indexOf('ettusen') === 0) text = 'etttusen' + text.slice(7);
        var n = 0, pos = text.indexOf('tusen');
        if (pos >= 0) {
            var thousands = integerWords(text.slice(0, pos) || 'ett');
            if (thousands === null || thousands < 1 || thousands > 999) return null;
            n += thousands * 1000; text = text.slice(pos + 5);
            if (text.indexOf('tusen') >= 0) return null;
        }
        pos = text.indexOf('hundra');
        if (pos >= 0) {
            var hundreds = integerWords(text.slice(0, pos) || 'ett');
            if (hundreds === null || hundreds < 1 || hundreds > 9) return null;
            n += hundreds * 100; text = text.slice(pos + 6);
            if (text.indexOf('hundra') >= 0) return null;
        }
        if (!text) return n || null;
        if (Object.prototype.hasOwnProperty.call(ones, text)) return n + ones[text];
        for (var key in tens) {
            if (Object.prototype.hasOwnProperty.call(tens, key) && text.indexOf(key) === 0) {
                var end = text.slice(key.length);
                if (!end || (Object.prototype.hasOwnProperty.call(ones, end) && ones[end] < 10)) return n + tens[key] + (end ? ones[end] : 0);
            }
        }
        return null;
    }
    function number(value) {
        var text = String(value == null ? '' : value).toLocaleLowerCase('sv-SE').trim().replace(/\u00a0/g, ' ');
        if (!text) return null;
        if (/^\d{1,3}(?: \d{3})+(?:[.,]\d{1,2})?$/.test(text)) return Number(text.replace(/ /g, '').replace(',', '.'));
        if (/^\d+(?:[.,]\d{1,2})?$/.test(text)) return Number(text.replace(',', '.'));
        if (/^(?:en|ett) halv(?:a)?$/.test(text)) return 0.5;
        var half = text.match(/^(.+?)\s+och\s+(?:en|ett)\s+halv(?:a)?$/);
        if (half) { var wholeHalf = number(half[1]); return wholeHalf === null || !Number.isInteger(wholeHalf) ? null : wholeHalf + 0.5; }
        var decimal = text.match(/^(.+?)\s+komma\s+(.+)$/);
        if (decimal) {
            var whole = number(decimal[1]), fraction = decimal[2].trim();
            if (whole === null || !Number.isInteger(whole)) return null;
            if (/^[a-zåäö]+$/.test(fraction) && !Object.prototype.hasOwnProperty.call(ones, fraction)) {
                var spokenFraction = integerWords(fraction);
                if (spokenFraction !== null && spokenFraction >= 10 && spokenFraction <= 99) return Number(whole + '.' + spokenFraction);
            }
            var digitParts = fraction.split(/\s+/).map(function (part) {
                if (/^\d{1,2}$/.test(part)) return part;
                return Object.prototype.hasOwnProperty.call(ones, part) && ones[part] < 10 ? String(ones[part]) : '';
            });
            if (digitParts.some(function (part) { return part === ''; })) return null;
            var digits = digitParts.join('');
            if (!/^\d{1,2}$/.test(digits)) return null;
            return Number(whole + '.' + digits);
        }
        // Only Swedish number words, not arbitrary text joined into a number.
        if (!/^[a-zåäö\s]+$/.test(text)) return null;
        text = text.replace(/(hundra|tusen)\s+och\s+/g, '$1 ');
        return integerWords(text.replace(/\s+/g, ''));
    }
    var units = { timme: 'tim', timmar: 'tim', tim: 'tim', styck: 'st', stycken: 'st', st: 'st', kilometer: 'km', km: 'km', dag: 'dag', dagar: 'dag', meter: 'm', m: 'm', kilo: 'kg', kilogram: 'kg', kg: 'kg' };
    // Take the longest valid number suffix, but do not drop unrecognized words or
    // interpret an unsupported amount as a shorter, different number.
    function numberSuffix(prefix) {
        var trimmed = prefix.replace(/\s+$/g, '');
        var tokens = Array.from(trimmed.matchAll(/\S+/g));
        for (var i = 0; i < tokens.length; i++) {
            var start = tokens[i].index;
            var parsed = number(trimmed.slice(start));
            if (parsed !== null) {
                var previous = i > 0 ? tokens[i - 1][0] : '';
                if (previous && !/[,;]$/.test(previous) && (number(previous) !== null || /^(?:och|komma)$/i.test(previous))) return null;
                return { value: parsed, start: start };
            }
        }
        return null;
    }
    // Also accept quantity first, including the common compact form "2st".
    // Never turn a total or a conflicting /st suffix into a unit price.
    function parseQuantityFirst(raw) {
        var prefix = raw.match(/^(.+?)\s*(timmar|timme|tim|stycken|styck|st|kilometer|km|dagar|dag|meter|m|kilogram|kilo|kg)(?=[\s,;]|$)/i);
        if (!prefix) return null;
        var quantity = number(prefix[1]);
        if (quantity === null) return null;
        var result = { name: '', qty: String(quantity), unit: units[prefix[2].toLocaleLowerCase('sv-SE')], price: '' };
        var rest = raw.slice(prefix[0].length).replace(/^[\s,;]+/, '').trim();
        var moneyPattern = /(?:^|[\s,;])(kronor|krona|kr)(?=[\s,;\/]|$)/gi;
        var currencies = [], match;
        while ((match = moneyPattern.exec(rest))) currencies.push({ index: match.index + match[0].length - match[1].length, end: moneyPattern.lastIndex });
        if (currencies.length !== 1) { result.name = rest; return result; }
        var amount = currencies[0];
        var before = rest.slice(0, amount.index).replace(/\s+$/, '');
        var price = numberSuffix(before);
        if (!price) { result.name = rest; return result; }
        result.name = before.slice(0, price.start).replace(/[\s,;]+$/, '').trim();
        var tail = rest.slice(amount.end).trim();
        var qualifier = tail.match(/^(?:(?:per|i)\s+|\/\s*)?(timme|timmen|tim|styck|stycken|st|kilometer|km|dag|meter|kg|kilo)$/i);
        var suffixUnit = qualifier ? qualifier[1].toLocaleLowerCase('sv-SE').replace(/^timmen$/, 'timme') : '';
        if (!/\b(?:total|totalt|totalpris|summa)\b/i.test(rest) && (!tail || (suffixUnit && units[suffixUnit] === result.unit))) result.price = String(price.value);
        return result;
    }
    function parseRow(text) {
        var result = { name: '', qty: '', unit: '', price: '' };
        var raw = String(text || '').trim().replace(/[.!?]+\s*$/, '');
        if (!raw) return result;
        var quantityFirst = parseQuantityFirst(raw);
        if (quantityFirst) return quantityFirst;
        // Protect decimal commas, but keep separators between dictated fields.
        var normalized = raw.replace(/(\d),(?=\d)/g, '$1.');
        var pieces = normalized.split(/\s*[,;]\s*/);
        var unitPattern = /(?:^|[\s,;])(timmar|timme|tim|stycken|styck|st|kilometer|km|dagar|dag|meter|m|kilogram|kilo|kg)(?=[\s,;]|$)/gi;
        var matches = [], match;
        while ((match = unitPattern.exec(normalized))) matches.push({ index: match.index + (match[0].length - match[1].length), end: unitPattern.lastIndex, word: match[1].toLocaleLowerCase('sv-SE') });
        // Do not count the unit in a price suffix, such as "per timme".
        matches = matches.filter(function (entry) { return !/(?:\bper|\bi)\s*$/i.test(normalized.slice(0, entry.index)); });
        var moneyPattern = /(?:^|[\s,;])(kronor|krona|kr)(?=[\s,;]|$)/gi, money = [];
        while ((match = moneyPattern.exec(normalized))) money.push({ index: match.index + (match[0].length - match[1].length), end: moneyPattern.lastIndex });
        var quantity = matches.length === 1 ? matches[0] : null;
        var quantityParsed = false;
        var amount = money.length === 1 ? money[0] : null;
        if (quantity && (!amount || quantity.end < amount.index)) {
            var qtyPrefix = normalized.slice(0, quantity.index).replace(/[,;]\s*$/, '');
            var q = numberSuffix(qtyPrefix);
            if (q) {
                quantityParsed = true;
                result.qty = String(q.value); result.unit = units[quantity.word];
                result.name = raw.slice(0, q.start).replace(/[\s,;]+$/, '').trim();
            }
        }
        // A total amount is not a unit price. Do not convert or guess it.
        if (amount && !/\b(?:total|totalt|totalpris|summa)\b/i.test(normalized)) {
            var priceStart = quantity ? quantity.end : 0;
            var pricePrefix = normalized.slice(priceStart, amount.index).replace(/^[\s,;]+/, '').replace(/^(?:à|á|a|pris(?:et)?|styckpris|per\s+enhet)\s+/i, '');
            var p = number(pricePrefix.trim());
            var tail = normalized.slice(amount.end).trim();
            var perUnit = tail.match(/^(?:per|i)\s+(timme|tim|styck|st|kilometer|km|dag|meter|kg|kilo)(?:n)?$/i);
            if (p !== null && (!tail || (perUnit && result.unit === units[perUnit[1].toLocaleLowerCase('sv-SE')]))) result.price = String(p);
        }
        if (!result.name && !quantityParsed) result.name = raw.slice(0, (pieces[0] || '').length).trim();
        return result;
    }
    var api = { parseRow: parseRow, number: number };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.InvoiceVoice = api;
})(typeof window !== 'undefined' ? window : null);
