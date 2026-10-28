import AppLayout from '../Layouts/AppLayout';
import HomeHub from '../Components/HomeHub';

/**
 * Home mega hub: big module search that live-filters the tiles, then one
 * tile section per built module. The post-login route renders the
 * Dashboard (Step 24), which embeds this same hub below its overview;
 * this page component remains for direct rendering/tests.
 */
export default function Home() {
    return (
        <AppLayout>
            <HomeHub />
        </AppLayout>
    );
}
