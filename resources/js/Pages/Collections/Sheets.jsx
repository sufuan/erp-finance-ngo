import CollectionReportEngine from '../../Components/CollectionReportEngine';

/** Collection sheets (Step 22): blank / target / with-data printable sheets. */
export default function CollectionSheets(props) {
    return <CollectionReportEngine basePath="/collection/sheets" headingKey="collection_sheet_section" {...props} />;
}
