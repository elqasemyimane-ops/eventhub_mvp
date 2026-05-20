async function loadEvents() {

    const kw =
        document.getElementById('search-input')?.value?.toLowerCase() || '';

    const cat =
        document.getElementById('filter-cat')?.value || '';

    const pl =
        document.getElementById('filter-places')?.value === 'available';

    showSkeletons();

    try {

        const res = await fetch('api/events.php', {

            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify({
                kw,
                cat,
                pl,
                tab: currentTab
            })
        });

        if (!res.ok) {

            throw new Error('Erreur serveur ' + res.status);
        }

        const list = await res.json();

        console.log(list);

        if (!Array.isArray(list) || list.length === 0) {

            document.getElementById('events-grid').innerHTML = `
                <div class="col-span-3 text-center py-16">
                    <div class="text-5xl mb-4">🔍</div>

                    <p class="font-display font-bold text-slate-600">
                        Aucun événement trouvé
                    </p>

                    <p class="text-slate-400 text-sm mt-2">
                        Modifiez vos filtres
                    </p>
                </div>
            `;

            return;
        }

        renderCards(list);

        updateHero(list);

    } catch (err) {

        console.error(err);

        document.getElementById('events-grid').innerHTML = `
            <div class="col-span-3 text-center py-16">

                <div class="text-5xl mb-4">⚠️</div>

                <p class="font-display font-bold text-red-500">
                    Erreur de chargement
                </p>

                <p class="text-slate-400 text-sm mt-2">
                    ${err.message}
                </p>

            </div>
        `;
    }
}
window.addEventListener('DOMContentLoaded', () => {

    loadEvents();

});