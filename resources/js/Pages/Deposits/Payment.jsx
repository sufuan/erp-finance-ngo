import MoneyMove from './MoneyMove';

export default function DepositPayment(props) {
    return <MoneyMove mode="payment" {...props} />;
}
