import { useEffect, useRef, useState } from 'react';
import { getDistricts, getDivisions, getUnions, getUpazilas } from 'bd-geo-info';
import { Field, inputCls } from './ui';
import { getLocale, t } from '../lib/i18n';

/**
 * Cascading Bangladesh geography dropdowns (Division → District →
 * Upazila/Thana → Union), fed ONLY by the `bd-geo-info` npm package —
 * its async utils lazy-load each level's JSON (code-split chunks) and
 * act as the API. There is no database geography and no country level
 * (Bangladesh only).
 *
 * Value shape (ids are the package's string ids; names are the English
 * names, stored verbatim by the backend as plain values):
 *   { division_id, division_name, district_id, district_name,
 *     upazila_id, upazila_name, union_id, union_name }
 * Changing a parent resets every level below it. Labels follow the app
 * locale (en → name, bn → bn_name).
 */
const LEVELS = ['division', 'district', 'upazila', 'union'];
const LABEL_KEYS = { division: 'division', district: 'district', upazila: 'upazila', union: 'union' };

// The package's division/district/union utils return {value, label} for
// one language at a time — fetch both and zip into {id, name, bn_name}.
// (The underlying JSON imports are module-cached, so the second fetch
// is free.) Upazila rows already carry both names.
const zip = (en, bn) => en.map((o, i) => ({
    id: String(o.value),
    name: o.label,
    bn_name: bn[i]?.label ?? o.label,
}));

async function loadOptions(level, parentId) {
    if (level === 'division') {
        return zip(await getDivisions('en'), await getDivisions('bn'));
    }
    if (level === 'district') {
        return zip(await getDistricts(parentId, 'en'), await getDistricts(parentId, 'bn'));
    }
    if (level === 'upazila') {
        const rows = await getUpazilas(parentId, 'en');
        return rows.map((r) => ({ id: String(r.id), name: r.name, bn_name: r.bn_name }));
    }
    return zip(await getUnions(parentId, 'en'), await getUnions(parentId, 'bn'));
}

export default function GeoCascade({ value, onChange, required = false, errors = {} }) {
    const locale = getLocale();
    const [options, setOptions] = useState({ division: [], district: [], upazila: [], union: [] });
    const [loading, setLoading] = useState({});
    const loadedFor = useRef({});

    const ids = {
        division: value?.division_id ? String(value.division_id) : '',
        district: value?.district_id ? String(value.district_id) : '',
        upazila: value?.upazila_id ? String(value.upazila_id) : '',
        union: value?.union_id ? String(value.union_id) : '',
    };

    const ensure = (level, parentId) => {
        if (loadedFor.current[level] === parentId) return;
        loadedFor.current[level] = parentId;
        if (level !== 'division' && !parentId) {
            setOptions((cur) => ({ ...cur, [level]: [] }));
            return;
        }
        setLoading((cur) => ({ ...cur, [level]: true }));
        loadOptions(level, parentId)
            .then((opts) => {
                if (loadedFor.current[level] === parentId) {
                    setOptions((cur) => ({ ...cur, [level]: opts }));
                }
            })
            .catch(() => {
                if (loadedFor.current[level] === parentId) {
                    setOptions((cur) => ({ ...cur, [level]: [] }));
                }
            })
            .finally(() => setLoading((cur) => ({ ...cur, [level]: false })));
    };

    useEffect(() => { ensure('division', 'root'); }, []);
    useEffect(() => { ensure('district', ids.division || null); }, [ids.division]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => { ensure('upazila', ids.district || null); }, [ids.district]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => { ensure('union', ids.upazila || null); }, [ids.upazila]); // eslint-disable-line react-hooks/exhaustive-deps

    const pick = (level, rawId) => {
        const id = rawId ? String(rawId) : '';
        const opt = options[level].find((o) => o.id === id);
        const next = { ...value };
        next[`${level}_id`] = id;
        next[`${level}_name`] = opt ? opt.name : '';
        for (const below of LEVELS.slice(LEVELS.indexOf(level) + 1)) {
            next[`${below}_id`] = '';
            next[`${below}_name`] = '';
        }
        onChange(next);
    };

    const parentOf = { district: 'division', upazila: 'district', union: 'upazila' };

    return (
        <div className="contents">
            {LEVELS.map((level) => {
                const parent = parentOf[level];
                const disabled = (parent && !ids[parent]) || loading[level];
                return (
                    <Field key={level} label={t(LABEL_KEYS[level])} required={required} error={errors[`${level}_id`]}>
                        <select
                            className={inputCls}
                            value={ids[level]}
                            disabled={disabled}
                            required={required}
                            onChange={(e) => pick(level, e.target.value)}
                        >
                            <option value="">{loading[level] ? t('loading') : '—'}</option>
                            {options[level].map((o) => (
                                <option key={o.id} value={o.id}>{locale === 'bn' ? o.bn_name : o.name}</option>
                            ))}
                        </select>
                    </Field>
                );
            })}
        </div>
    );
}
