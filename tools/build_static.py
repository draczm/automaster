#!/usr/bin/env python3
"""Собирает статическую демо-версию сайта (без PHP и базы) в папку docs/.

Запуск (из корня проекта), когда PHP-сервер уже поднят на демо-базе:
    DB_NAME=automaster_demo php -S 127.0.0.1:8099 -t .
    python tools/build_static.py http://127.0.0.1:8099

Результат:
    docs/                 - гостевая часть (главная, вход, регистрация, запись)
    docs/s/admin/         - страницы администратора (после входа)
    docs/s/emp/           - страницы сотрудника (после входа)
    docs/assets/          - общие стили, скрипты, картинки
"""
import hashlib
import json
import os
import re
import shutil
import sys
import urllib.parse
import urllib.request
from datetime import date
from http.cookiejar import CookieJar

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8099').rstrip('/')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'docs')

GUEST_PAGES = ['index.php', 'login.php', 'register.php', 'appointment.php']
ROLES = {
    'admin': {
        'dir': 's/admin',
        'login': ('admin@automaster.local', 'Admin123!'),
        'start': ['admin/index.php'],
        'allow': lambda p: p.startswith('admin/'),
        'limit': 45,
    },
    'client': {
        'dir': 's/client',
        'login': ('client@automaster.local', 'Client123!'),
        'start': ['profile.php', 'index.php', 'appointment.php'],
        'allow': lambda p: p in ('profile.php', 'index.php', 'appointment.php'),
        'limit': 20,
    },
    'emp': {
        'dir': 's/emp',
        'login': ('user@automaster.local', 'User123!'),
        'start': ['master-schedule.php', 'master-training.php'],
        'allow': lambda p: p in ('master-schedule.php', 'master-training.php'),
        'limit': 30,
    },
}
SKIP_WORDS = ('logout', 'delete', 'remove')


def make_opener():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))


def get(opener, url):
    try:
        with opener.open(url, timeout=30) as r:
            return r.geturl(), r.read().decode('utf-8', 'replace')
    except Exception as e:  # noqa: BLE001
        print('  ! не удалось получить', url, e)
        return url, None


def login(opener, email, password):
    data = urllib.parse.urlencode({'email': email, 'password': password}).encode()
    opener.open(BASE + '/login.php', data=data, timeout=30).read()


def page_key(path, query):
    return path + ('?' + query if query else '')


def out_name(path, query):
    name = re.sub(r'\.php$', '', path)
    if query:
        name += '~' + hashlib.md5(query.encode()).hexdigest()[:8]
    return name + '.html'


def extract_links(html):
    return re.findall(r'(?:href|action)="([^"#][^"]*)"', html)


def resolve(cur_path, href):
    """Возвращает (path, query) внутри сайта или None."""
    if re.match(r'^(https?:|mailto:|tel:|javascript:|data:)', href):
        return None
    u = urllib.parse.urlsplit(href)
    path = os.path.normpath(os.path.join(os.path.dirname(cur_path), u.path)).replace('\\', '/')
    if path.startswith('..') or not path.endswith('.php'):
        return None
    return path, u.query


def crawl(role_name):
    """Обходит страницы роли. Возвращает {key: (path, query, html)}."""
    opener = make_opener()
    if role_name == 'guest':
        starts = GUEST_PAGES
        allow = lambda p: p in GUEST_PAGES  # noqa: E731
        limit = 20
    else:
        cfg = ROLES[role_name]
        login(opener, *cfg['login'])
        starts, allow, limit = cfg['start'], cfg['allow'], cfg['limit']

    pages, queue = {}, [(p, '', 0) for p in starts]
    while queue and len(pages) < limit:
        path, query, depth = queue.pop(0)
        key = page_key(path, query)
        if key in pages:
            continue
        url = BASE + '/' + path + ('?' + query if query else '')
        final, html = get(opener, url)
        if html is None:
            continue
        if not final.split('?')[0].endswith(path):  # редирект (например, на вход)
            print('  - редирект, пропуск', key)
            continue
        pages[key] = (path, query, html)
        print('  +', role_name, key)
        for href in extract_links(html):
            if any(w in href for w in SKIP_WORDS):
                continue
            r = resolve(path, href)
            if not r:
                continue
            p, q = r
            if not allow(p):
                continue
            if q and depth >= 2:
                continue
            if page_key(p, q) not in pages:
                queue.append((p, q, depth + (1 if q else 0)))
    return pages


def rel(from_file, to_file):
    return os.path.relpath(to_file, os.path.dirname(from_file)).replace('\\', '/')


def rewrite(html, cur_path, cur_out, role_root, pages_here, guest_pages, assets_dir, demo_js):
    """Переписывает ссылки на .html-страницы и общие ассеты."""

    def fix_attr(m):
        attr, val = m.group(1), m.group(2)
        # общие ассеты
        if re.match(r'^(\.\./)*assets/', val):
            target = os.path.join(assets_dir, re.sub(r'^(\.\./)*assets/', '', val))
            return '%s="%s"' % (attr, rel(cur_out, target))
        if val.startswith('#') or re.match(r'^(https?:|mailto:|tel:|javascript:|data:)', val):
            return m.group(0)
        u = urllib.parse.urlsplit(val)
        frag = ('#' + u.fragment) if u.fragment else ''
        if 'logout.php' in u.path:
            return '%s="%s"' % (attr, rel(cur_out, os.path.join(OUT, 'index.html')))
        r = resolve(cur_path, val)
        if not r:
            return m.group(0)
        p, q = r
        key = page_key(p, q)
        if key in pages_here:
            target = os.path.join(role_root, out_name(p, q))
        elif page_key(p, '') in pages_here:
            target = os.path.join(role_root, out_name(p, ''))
        elif p in GUEST_PAGES:
            target = os.path.join(OUT, out_name(p, ''))
        else:
            return '%s="#"' % attr
        return '%s="%s%s"' % (attr, rel(cur_out, target), frag)

    html = re.sub(r'\b(href|src|action)="([^"]*)"', fix_attr, html)
    tag = '<script src="%s" defer></script>' % rel(cur_out, demo_js)
    return html.replace('</body>', tag + '\n</body>') if '</body>' in html else html + tag


def build_ajax(opener):
    data = {}
    for sid in range(1, 20):
        _, txt = get(opener, '%s/get-available-dates.php?service_id=%d&days=60' % (BASE, sid))
        if not txt:
            continue
        j = json.loads(txt)
        if not j.get('success') or not j.get('dates'):
            continue
        data['dates:%d' % sid] = txt
        for d in j['dates']:
            _, t = get(opener, '%s/get-available-times.php?service_id=%d&date=%s' % (BASE, sid, d['date']))
            data['times:%d:%s' % (sid, d['date'])] = t
            for tm in json.loads(t).get('times', []):
                _, m = get(opener, '%s/get-available-masters.php?service_id=%d&date=%s&time=%s'
                           % (BASE, sid, d['date'], tm['time']))
                data['masters:%d:%s:%s' % (sid, d['date'], tm['time'])] = m
    return data


def main():
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    os.makedirs(OUT)
    assets_dir = os.path.join(OUT, 'assets')
    shutil.copytree(os.path.join(ROOT, 'assets'), assets_dir)
    demo_src = os.path.join(ROOT, 'tools', 'demo.js')
    demo_js = os.path.join(assets_dir, 'js', 'demo.js')
    shutil.copy(demo_src, demo_js)

    ajax = build_ajax(make_opener())
    os.makedirs(os.path.join(assets_dir, 'data'), exist_ok=True)
    with open(os.path.join(assets_dir, 'data', 'ajax.json'), 'w', encoding='utf-8') as f:
        json.dump({'built': date.today().isoformat(), 'items': ajax}, f, ensure_ascii=False)
    print('ajax записей:', len(ajax))

    jobs = [('guest', OUT)] + [(n, os.path.join(OUT, c['dir'])) for n, c in ROLES.items()]
    for name, role_root in jobs:
        pages = crawl(name)
        for key, (path, query, html) in pages.items():
            out_file = os.path.join(role_root, out_name(path, query))
            os.makedirs(os.path.dirname(out_file), exist_ok=True)
            html = rewrite(html, path, out_file, role_root, pages, GUEST_PAGES, assets_dir, demo_js)
            with open(out_file, 'w', encoding='utf-8') as f:
                f.write(html)

    open(os.path.join(OUT, '.nojekyll'), 'w').close()
    print('Готово:', OUT)


if __name__ == '__main__':
    main()
