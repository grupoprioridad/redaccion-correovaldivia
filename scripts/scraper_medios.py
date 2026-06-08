#!/usr/bin/env python3
"""
Scraper de medios regionales para El Correo de Valdivia
Extrae titulares y resúmenes de fuentes configuradas en la BD
"""
import sys
import os
import json
import re
import hashlib
import unicodedata
from datetime import datetime
from urllib.parse import urljoin

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import requests
from bs4 import BeautifulSoup

# ── Config ─────────────────────────────────────────────────────────────
DATA_DIR = os.environ.get('SCRAPER_DATA_DIR', '/var/www/redaccion.elcorreodevaldivia/datos')
os.makedirs(DATA_DIR, exist_ok=True)

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (compatible; CorreoValdiviaBot/1.0; +https://elcorreodevaldivia.cl)',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'es-CL,es;q=0.9',
}

TIMEOUT = 15

# ── Scrapers por sitio ─────────────────────────────────────────────────

def scraper_lavozdepucon(soup, base_url):
    """La Voz de Pucón"""
    noticias = []
    for article in soup.select('article, .post, .entry, .noticia, [class*="post"], [class*="entry"], [class*="article"]'):
        title_el = article.select_one('h1 a, h2 a, h3 a, .entry-title a, .post-title a')
        if not title_el:
            title_el = article.select_one('h1, h2, h3, .entry-title, .post-title')
        if not title_el:
            continue
        titulo = title_el.get_text(strip=True)
        if len(titulo) < 15:
            continue
        url = title_el.get('href', '') if title_el.name == 'a' else ''
        if url and not url.startswith('http'):
            url = urljoin(base_url, url)
        resumen_el = article.select_one('.entry-summary, .post-excerpt, p, .excerpt, .resumen')
        resumen = resumen_el.get_text(strip=True)[:300] if resumen_el else ''
        noticias.append({
            'titulo': limpiar(titulo),
            'resumen': limpiar(resumen),
            'url': url,
            'fuente': 'La Voz de Pucón',
        })
    return noticias

def scraper_soychile(soup, base_url):
    """Soy Chile / Soy Valdivia"""
    noticias = []
    for article in soup.select('article, .card, .noticia, [class*="card"], [class*="news"], li.noticia'):
        title_el = article.select_one('h2 a, h3 a, h4 a, .card-title a, .title a')
        if not title_el:
            title_el = article.select_one('h2, h3, h4, .card-title, .title')
        if not title_el:
            continue
        titulo = title_el.get_text(strip=True)
        if len(titulo) < 15:
            continue
        url = title_el.get('href', '') if title_el.name == 'a' else ''
        if url and not url.startswith('http'):
            url = urljoin(base_url, url)
        resumen_el = article.select_one('.card-text, .summary, p, .desc, .bajada')
        resumen = resumen_el.get_text(strip=True)[:300] if resumen_el else ''
        noticias.append({
            'titulo': limpiar(titulo),
            'resumen': limpiar(resumen),
            'url': url,
            'fuente': 'Soy Valdivia',
        })
    return noticias

def scraper_adn(soup, base_url):
    """ADN Radio"""
    noticias = []
    for article in soup.select('article, .noticia, [class*="news"], [class*="post"], .feed-item'):
        title_el = article.select_one('h2 a, h3 a, .title a, a h2, a h3')
        if not title_el:
            title_el = article.select_one('h2, h3, .title, a')
        if not title_el:
            continue
        titulo = title_el.get_text(strip=True)
        if len(titulo) < 15:
            continue
        url = ''
        if title_el.name == 'a':
            url = title_el.get('href', '')
        elif title_el.parent and title_el.parent.name == 'a':
            url = title_el.parent.get('href', '')
        if url and not url.startswith('http'):
            url = urljoin(base_url, url)
        resumen_el = article.select_one('p, .summary, .bajada, .desc')
        resumen = resumen_el.get_text(strip=True)[:300] if resumen_el else ''
        noticias.append({
            'titulo': limpiar(titulo),
            'resumen': limpiar(resumen),
            'url': url,
            'fuente': 'ADN Radio',
        })
    return noticias

def scraper_generico(soup, base_url, nombre_fuente):
    """Scraper genérico que busca patrones comunes"""
    noticias = []
    selectores = [
        'article', '.post', '.entry', '.noticia', '.news-item',
        '[class*="post"]', '[class*="entry"]', '[class*="card"]',
        'li.noticia', '.feed-item', '.item-list article', 'main article'
    ]
    for selector in selectores:
        elements = soup.select(selector)
        if elements:
            for article in elements:
                title_el = article.select_one('h1 a, h2 a, h3 a, h4 a, .title a, .entry-title a')
                if not title_el:
                    title_el = article.select_one('h1, h2, h3, h4, .title, .entry-title')
                if not title_el:
                    continue
                titulo = title_el.get_text(strip=True)
                if len(titulo) < 20:
                    continue
                url = title_el.get('href', '') if title_el.name == 'a' else ''
                if url and not url.startswith('http'):
                    url = urljoin(base_url, url)
                resumen_el = article.select_one('p, .summary, .excerpt, .bajada, .desc, .entry-summary')
                resumen = resumen_el.get_text(strip=True)[:300] if resumen_el else ''
                noticias.append({
                    'titulo': limpiar(titulo),
                    'resumen': limpiar(resumen),
                    'url': url,
                    'fuente': nombre_fuente,
                })
            if noticias:
                break
    return noticias

# ── Helpers ────────────────────────────────────────────────────────────

def limpiar(texto):
    if not texto:
        return ''
    texto = re.sub(r'\s+', ' ', texto).strip()
    return texto

def fingerprint(noticia):
    """Genera un hash único para evitar duplicados"""
    raw = (noticia.get('titulo', '') + noticia.get('url', '')).encode('utf-8')
    return hashlib.md5(raw).hexdigest()

def scrape_fuente(fuente):
    """Scrapea una fuente y devuelve lista de noticias"""
    nombre = fuente['nombre']
    url = fuente['url']
    tipo = fuente.get('tipo', 'portada')
    
    print(f"  → Scrapeando {nombre}: {url}")
    try:
        resp = requests.get(url, headers=HEADERS, timeout=TIMEOUT, allow_redirects=True)
        resp.raise_for_status()
        resp.encoding = resp.apparent_encoding or 'utf-8'
    except Exception as e:
        print(f"    ✗ Error HTTP: {e}")
        return []
    
    soup = BeautifulSoup(resp.text, 'lxml')
    
    # Elegir scraper según fuente
    if 'lavozdepucon' in url:
        noticias = scraper_lavozdepucon(soup, url)
    elif 'soychile' in url:
        noticias = scraper_soychile(soup, url)
    elif 'adnradio' in url:
        noticias = scraper_adn(soup, url)
    else:
        noticias = scraper_generico(soup, url, nombre)
    
    # También intentar RSS si existe
    rss_urls = [
        url.rstrip('/') + '/feed',
        url.rstrip('/') + '/feed/',
        url.rstrip('/') + '/rss',
        url.rstrip('/') + '/rss.xml',
        url.rstrip('/') + '/feed.xml',
    ]
    for rss_url in rss_urls:
        try:
            rss_resp = requests.get(rss_url, headers=HEADERS, timeout=8)
            if rss_resp.status_code == 200 and 'xml' in rss_resp.headers.get('Content-Type', ''):
                rss_soup = BeautifulSoup(rss_resp.text, 'lxml-xml')
                items = rss_soup.select('item, entry')
                for item in items:
                    titulo = item.select_one('title')
                    desc = item.select_one('description, summary, content')
                    link = item.select_one('link')
                    if titulo:
                        t = titulo.get_text(strip=True)
                        if len(t) >= 15:
                            noticias.append({
                                'titulo': limpiar(t),
                                'resumen': limpiar(desc.get_text(strip=True)[:300] if desc else ''),
                                'url': link.get_text(strip=True) if link else '',
                                'fuente': nombre,
                            })
                if items:
                    print(f"    + {len(items)} noticias vía RSS")
                break
        except:
            pass
    
    print(f"    → {len(noticias)} noticias encontradas")
    return noticias

def main():
    """Ejecuta el scraper para todas las fuentes activas"""
    import subprocess
    import pymysql
    
    # Conectar a la BD
    try:
        conn = pymysql.connect(
            host='localhost',
            user='redaccion_user',
            password='Redacc2026!',
            database='el_correo_redaccion',
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
    except Exception as e:
        print(f"Error conectando BD: {e}")
        # Fallback: leer fuentes desde JSON local
        fuentes = [
            {'nombre': 'La Voz de Pucón', 'url': 'https://www.lavozdepucon.cl', 'tipo': 'portada'},
            {'nombre': 'Soy Valdivia', 'url': 'https://www.soychile.cl/Valdivia', 'tipo': 'portada'},
            {'nombre': 'La Nación', 'url': 'https://www.lanacion.cl', 'tipo': 'portada'},
            {'nombre': 'El Mostrador', 'url': 'https://www.elmostrador.cl', 'tipo': 'portada'},
        ]
        cursor = None
    else:
        with conn.cursor() as cursor:
            cursor.execute("SELECT * FROM scraper_fuentes WHERE activo = 1")
            fuentes = cursor.fetchall()
    
    todas_las_noticias = []
    fingerprints_vistos = set()
    
    # Cargar noticias previas para dedup
    archivo_prev = os.path.join(DATA_DIR, 'noticias_scrapeadas.json')
    if os.path.exists(archivo_prev):
        try:
            previas = json.load(open(archivo_prev, 'r', encoding='utf-8'))
            if isinstance(previas, dict):
                previas = []
            fingerprints_vistos = set()
            for n in previas:
                fingerprints_vistos.add(fingerprint(n))
        except:
            previas = []
    else:
        previas = []
    
    print(f"Iniciando scraper de {len(fuentes)} fuentes...")
    
    for fuente in fuentes:
        noticias = scrape_fuente(fuente)
        for n in noticias:
            fp = fingerprint(n)
            if fp not in fingerprints_vistos:
                fingerprints_vistos.add(fp)
                n['scrapeado_en'] = datetime.now().isoformat()
                todas_las_noticias.append(n)
    
    # Mezclar con noticias previas (mantener últimas 500)
    todas = todas_las_noticias + previas
    todas = todas[:500]
    
    with open(archivo_prev, 'w', encoding='utf-8') as f:
        json.dump(todas, f, ensure_ascii=False, indent=2)
    
    # Actualizar timestamp en BD
    if cursor:
        cursor.execute("UPDATE scraper_fuentes SET ultimo_scrape = NOW() WHERE activo = 1")
        conn.commit()
        conn.close()
    
    print(f"\n✅ Scraper completado: {len(todas_las_noticias)} nuevas, {len(todas)} total en archivo")
    return todas

if __name__ == '__main__':
    main()
