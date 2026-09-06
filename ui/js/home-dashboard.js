/* Herika's D3 cloud geometry and frequency sizing, served locally under the existing CSP. */
(() => {
    const svg = document.getElementById('word-cloud');
    if (!svg || !window.d3?.layout?.cloud) return;
    const words = JSON.parse(svg.dataset.words || '[]');
    const display = document.getElementById('word-count-display');
    const colors = ['#bc9d5a', '#c6ab70', '#d0b986', '#dac79c', '#e4d5b2'];
    let layout = null;
    let width = 0;
    let resizeTimer;

    // Restart from immutable word data so resized layouts cannot retain stale positions or sprites.
    const render = () => {
        const nextWidth = Math.floor(svg.clientWidth);
        if (!nextWidth || nextWidth === width) return;
        width = nextWidth;
        layout?.stop();
        svg.replaceChildren();
        display.textContent = '';
        layout = d3.layout.cloud().size([width, 500]).words(words.map(word => ({ ...word })))
            .padding(5).rotate(() => 0).font('Arial').fontSize(word => word.size)
            .on('end', placed => {
                d3.select(svg).append('g').attr('transform', `translate(${width / 2},250)`)
                    .selectAll('text').data(placed).enter().append('text')
                    .attr('class', 'word-cloud-text').attr('text-anchor', 'middle')
                    .attr('transform', word => `translate(${word.x},${word.y})`)
                    .attr('tabindex', '0').attr('aria-label', word => `${word.text}: ${word.count} uses`)
                    .style('font-size', word => `${word.size}px`).style('font-family', 'Arial')
                    .style('fill', (_, index) => colors[index % colors.length]).text(word => word.text)
                    .on('mouseover focus', (_, word) => { display.textContent = `${word.text} [${word.count}]`; })
                    .on('mouseout blur', () => { display.textContent = ''; });
            });
        layout.start();
    };
    const observer = new ResizeObserver(() => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 100);
    });
    observer.observe(svg);
    render();
    window.addEventListener('pagehide', () => { observer.disconnect(); clearTimeout(resizeTimer); layout?.stop(); });
})();
