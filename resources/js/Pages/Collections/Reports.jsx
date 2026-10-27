import CollectionReportEngine from '../../Components/CollectionReportEngine';

/** Collection reports + balance reports (Steps 22–23) on the shared engine. */
export default function CollectionReports(props) {
    const basePath = props.group === 'balance' ? '/collection/balances' : '/collection/reports';
    const headingKey = props.group === 'balance' ? 'balance_reports' : 'collection_reports';
    return <CollectionReportEngine basePath={basePath} headingKey={headingKey} {...props} />;
}
