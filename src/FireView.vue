<template>
    <k-panel-inside>
        <k-header data-has-buttons="true">
            <k-header-title>{{ $t("e9li.kirby-fire.headline") }}</k-header-title>
            <k-header-buttons slot="buttons">
                <k-button-group>
                    <k-button v-if="!isHeatingUp && index === 0" variant="filled" icon="fire" theme="positive" @click="start()">{{ $t("e9li.kirby-fire.button.fireup") }}</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="fire" theme="positive" @click="start()">{{ $t("e9li.kirby-fire.button.continue") }}</k-button>
                    <k-button v-if="!isHeatingUp && index !== 0" variant="filled" icon="cancel" theme="negative" @click="reset()">{{ $t("e9li.kirby-fire.button.extinguish") }}</k-button>
                    <k-button v-if="isHeatingUp" variant="filled" icon="cancel" theme="negative" @click="pause()">{{ $t("e9li.kirby-fire.button.stop") }}</k-button>
                    <k-button v-if="!isHeatingUp" variant="filled" icon="trash" @click="clearCache()">{{ $t("e9li.kirby-fire.button.clear") }}</k-button>
                </k-button-group>
            </k-header-buttons>
        </k-header>
        <k-box v-if="status.active === false" theme="negative" :text="$t('e9li.kirby-fire.status.inactive')"/>
        <p v-if="status.active === true" class="k-fire-status">
            {{ $t("e9li.kirby-fire.status.active") }}<template v-if="status.count !== null"> — {{ $t("e9li.kirby-fire.status.count", { count: status.count }) }}</template>
        </p>
        <k-grid style="--columns: 1; gap: var(--spacing-8)">
            <k-empty v-if="items.length === 0" icon="blaze" :text="$t('e9li.kirby-fire.empty')"/>
            <div v-if="items.length > 0" class="k-table">
                <table>
                    <thead>
                    <tr>
                        <th class="k-table-index-column">#</th>
                        <th class="k-boiler-url" data-mobile="true">{{ $t("e9li.kirby-fire.column.url") }}</th>
                        <th class="k-state-column" data-mobile="true">{{ $t("e9li.kirby-fire.column.state") }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="(item, i) in items" :key="item.url">
                        <td class="k-table-index-column">
                            <span class="k-table-index">{{ i + 1 }}</span>
                        </td>
                        <td data-mobile="true">
                            <div class="truncate">{{ item.url }}</div>
                            <div v-if="item.error" class="error">{{ item.error }}</div>
                        </td>
                        <td class="k-state-column" data-mobile="true">
                            <div class="badge" :class="item.state">
                                <k-icon v-if="item.state === 'no-fire'" type="blaze"/>
                                <k-icon v-if="item.state === 'fire-up'" type="fire"/>
                                <k-icon v-if="item.state === 'fire-on'" type="fireFilled"/>
                                <k-icon v-if="item.state === 'extinguished'" type="cancel"/>
                                <span>{{ stateText(item.state) }}</span>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </k-grid>

    </k-panel-inside>
</template>

<script>
// pause between requests, keeps the server from being hammered
const PACE_MS = 150;

export default {
    name: "fireView",
    data() {
        return {
            isHeatingUp: false,
            index: 0,
            items: [],
            known: new Set(),
            origins: new Set(),
            status: {
                active: null,
                count: null,
            },
        }
    },
    created() {
        this.load();
    },
    methods: {
        stateText(state) {
            return this.$t("e9li.kirby-fire.state." + state);
        },
        load() {
            this.$api.get("fire/pages").then((data) => {
                this.items = data;
                this.known = new Set(data.map((item) => item.url));
                // the base argument keeps root-relative URLs working (sites
                // without a configured url option)
                this.origins = new Set(data.map((item) => new URL(item.url, location.origin).origin));
            });
            this.loadStatus();
        },
        loadStatus() {
            // the list's "no fire" states are client-side only — this shows
            // the actual server-side cache state next to them
            this.$api.get("fire/status").then((data) => {
                this.status = data;
            });
        },
        // browser-side mirror of Urls::media() — same-site /media/ URLs
        // from src/srcset attributes
        mediaUrls(html) {
            const urls = new Set();

            for (const match of html.matchAll(/(?:src|srcset)="([^"]+)"/gi)) {
                for (const candidate of match[1].split(",")) {
                    const url = candidate.trim().split(/\s+/)[0] || "";

                    if (url.includes("/media/") && this.isAllowed(url)) {
                        urls.add(url);
                    }
                }
            }

            return [...urls];
        },
        isAllowed(url) {
            try {
                return this.origins.has(new URL(url, location.origin).origin);
            } catch {
                return false;
            }
        },
        // The browser fetches the page itself: a normal top-level request,
        // exactly like a visitor. Unlike the old server-side self-request
        // this cannot deadlock single-worker servers and does not depend on
        // the server being able to reach its own public URL — which is what
        // made the Panel fail on shared hosting. credentials are omitted so
        // the response stays as cacheable as for an anonymous visitor.
        async warm(item) {
            try {
                const response = await fetch(item.url, {
                    credentials: "omit",
                    cache: "no-store",
                });

                const expected404 = item.isErrorPage === true && response.status === 404;

                if (response.ok === false && expected404 === false) {
                    return { ...item, state: "extinguished", error: "HTTP " + response.status };
                }

                const type = response.headers.get("content-type") || "";
                const media = type.includes("text/html")
                    ? this.mediaUrls(await response.text())
                    : [];

                return { ...item, state: "fire-on", error: null, media };
            } catch {
                // cross-origin (a language on its own domain) or a network
                // failure — fall back to the server-side warm route
                try {
                    return await this.$api.post("fire/up", item);
                } catch {
                    return {
                        ...item,
                        state: "extinguished",
                        error: this.$t("e9li.kirby-fire.error.request"),
                    };
                }
            }
        },
        queueMedia(index, media) {
            // thumbs of a warmed page are queued right behind it, so Kirby's
            // media route generates them as part of the same crawl
            const fresh = (media || []).filter((url) => !this.known.has(url));

            fresh.forEach((url) => this.known.add(url));

            if (fresh.length > 0) {
                const additions = fresh.map((url) => ({
                    url,
                    language: this.items[index].language,
                    state: "no-fire",
                }));
                this.items.splice(index + 1, 0, ...additions);
            }
        },
        heatUp(index) {
            this.index = index;

            // past the last row: the crawl is complete — back to the idle
            // buttons, otherwise "Stop" stays although nothing runs anymore
            if (index >= this.items.length) {
                if (this.isHeatingUp) {
                    this.$panel.notification.success({
                        message: this.$t("e9li.kirby-fire.notification.done"),
                        icon: "fire",
                    });
                }

                this.isHeatingUp = false;
                this.index = 0;
                this.loadStatus();
                return;
            }

            if (this.isHeatingUp !== true) {
                return;
            }

            this.items[index].state = "fire-up";

            this.warm(this.items[index])
                .then((data) => {
                    // splice instead of items[index] = data — Vue 2 cannot
                    // observe by-index array assignments, which left the last
                    // row stuck on "fire up"
                    this.items.splice(index, 1, data);
                    this.queueMedia(index, data.media);
                })
                .catch(() => {
                    // a failed request must not stall the crawl
                    this.items.splice(index, 1, {
                        ...this.items[index],
                        state: "extinguished",
                        error: this.$t("e9li.kirby-fire.error.request"),
                    });
                })
                .finally(() => {
                    setTimeout(() => {
                        this.heatUp(index + 1);
                    }, PACE_MS);
                });
        },
        start() {
            this.isHeatingUp = true;
            this.heatUp(this.index);
        },
        pause() {
            this.isHeatingUp = false;
        },
        reset() {
            this.isHeatingUp = false;
            this.index = 0;
            this.load();
        },
        clearCache() {
            this.$api.post("fire/clear").then(() => {
                this.reset();
            });
        },
    },
}
</script>

<style lang="scss" scoped>

.k-fire-status {
    margin-bottom: var(--spacing-4);
    color: var(--color-text-dimmed);
    font-size: var(--text-sm);
}

.k-table-index-column {
    width: 4.5rem;
}

.k-bar {
    padding-bottom: var(--spacing-4);
}

.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.error {
    color: var(--color-red-600);
    font-size: var(--text-xs);
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-1);
    border-radius: var(--rounded-sm);
    padding: var(--spacing-1) var(--spacing-2);
    background: var(--color-gray-200);
    color: var(--color-gray-800);
}

.fire-up {
    padding-left: var(--spacing-1);
    background: var(--color-yellow-300);
    color: var(--color-yellow-800);
}

.fire-on {
    padding-left: var(--spacing-1);
    background: var(--color-green-300);
    color: var(--color-green-800);
}

.extinguished {
    padding-left: var(--spacing-1);
    background: var(--color-red-300);
    color: var(--color-red-800);
}

.k-language-column {
    width: 6rem;
}

.k-state-column {
    text-align: right;
    width: 9rem;
}
</style>
